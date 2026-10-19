<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiBlob;
use App\Models\PhrEhiEntity;
use App\Models\PhrEhiFinding;
use App\Models\PhrEhiOccurrence;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrEhiTableAccount;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Services\PHR\Import\Ehi\Mapping\EhiCanonicalJson;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingRegistry;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Projects the mapped tables of one bound, preserved, accounted package into
 * source entities, immutable revisions, and occurrences (issue #213, M2). It
 * never writes clinical data; proposals come later, from the mappings.
 *
 * - Keys are never guessed: each mapped table's expected primary key must equal
 *   the key the package's own dictionary documents (re-read from the stored
 *   bytes) and appear in the member header, or the table is skipped with a
 *   mapping_key_mismatch finding and keeps its retained_unmapped disposition.
 * - Table bytes are re-read from storage into a private temp file, hashed while
 *   copied, and parsed only if they match the manifest digest.
 * - Entity identity is (source, table, digest of the full key as raw strings),
 *   so "007" and "7" stay distinct; a revision is (entity, digest of every
 *   column's raw value); an occurrence is (member, physical line).
 * - A row with an empty or null key cell is quarantined without an entity
 *   (missing_key); every occurrence of a key repeated within one member is
 *   quarantined (duplicate_key) and yields no proposal.
 * - Writes happen in bounded chunks under the patient artifact guard and the
 *   package lease. Every write is an insert-or-ignore on a unique key, so an
 *   interrupted run resumes at its first unfinished table and converges.
 *
 * Findings and exceptions are structural: never a table, column, or cell value.
 */
final class EhiProjectionService
{
    public const string VERSION = 'ehi-projection-1';

    /** detail_json.stage on every finding this service writes. */
    public const string FINDING_STAGE = 'projection';

    public const string FAILURE_UNEXPECTED = 'unexpected';

    public const string FAILURE_ACCESS_REVOKED = 'access_revoked';

    private const string TABLE_PROJECTED = 'projected';

    private const string TABLE_MISMATCHED = 'mismatched';

    private const string TABLE_ABSENT = 'absent';

    private const int CHUNK_ROWS = 500;

    public function __construct(
        private readonly PhrPatientArtifactWriteGuard $writeGuard,
        private readonly PhrPatientAccessService $access,
        private readonly EhiMappingRegistry $registry,
        private readonly EhiArchiveGuard $archiveGuard,
    ) {}

    /** Whether the package must be (re-)projected before a preview can rely on it. */
    public function needsProjection(PhrEhiPackage $package): bool
    {
        return $package->clinical_status !== PhrEhiPackage::CLINICAL_PROJECTED
            || ($package->checkpoint_json['projection']['registry'] ?? null) !== $this->registry->fingerprint();
    }

    /**
     * @throws EhiProjectionRefused
     * @throws AuthorizationException the actor cannot (or can no longer) write this patient
     * @throws ModelNotFoundException the patient or package no longer exists
     */
    public function project(PhrEhiPackage $package, int $actorUserId): EhiProjectionResult
    {
        $patient = PhrPatient::query()->findOrFail($package->patient_id);
        $this->access->ensureCanWrite($patient, $actorUserId);
        $package = PhrEhiPackage::query()->whereKey($package->id)->where('patient_id', $patient->id)->firstOrFail();
        $this->ensureReady($package);
        if (! $this->needsProjection($package)) {
            return $this->result($package, true);
        }

        $token = (string) Str::uuid();
        $fingerprint = $this->registry->fingerprint();
        $this->claim($package, $actorUserId, $token, $fingerprint);

        try {
            $limits = EhiLimits::fromConfig();
            $dictionary = $this->dictionary($package, $limits);
            $members = $this->tableMembers($package);
            foreach ($this->registry->tables() as $table => $key) {
                $done = $this->checkpoint($package)['tables'] ?? [];
                if (isset($done[$table])) {
                    continue;
                }
                $member = $members[strtoupper($table)] ?? null;
                $outcome = $member === null
                    ? self::TABLE_ABSENT
                    : $this->projectTable($package, $member, $table, $key, $dictionary, $limits, $token, $actorUserId);
                $this->underLease($package, $token, $actorUserId, function (PhrPatient $patient, PhrEhiPackage $current) use ($table, $outcome): void {
                    $checkpoint = $current->checkpoint_json ?? [];
                    $checkpoint['projection']['tables'][$table] = $outcome;
                    $current->checkpoint_json = $checkpoint;
                });
            }

            $this->underLease($package, $token, $actorUserId, function (PhrPatient $patient, PhrEhiPackage $current): void {
                $current->forceFill([
                    'clinical_status' => PhrEhiPackage::CLINICAL_PROJECTED,
                    'failure_category' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                ]);
            });
        } catch (Throwable $failure) {
            $this->recordFailure($package, $token, $failure);

            throw $failure;
        }

        return $this->result($package->refresh(), false);
    }

    private function ensureReady(PhrEhiPackage $package): void
    {
        if ($package->preservation_status !== PhrEhiPackage::PRESERVATION_PRESERVED
            || $package->accounting_status !== PhrEhiPackage::ACCOUNTING_ACCOUNTED) {
            throw new EhiProjectionRefused(EhiProjectionRefused::NOT_READY, 'the package is not preserved and accounted');
        }
        if ($package->source_id === null || $package->binding_status !== PhrEhiPackage::BINDING_BOUND) {
            throw new EhiProjectionRefused(EhiProjectionRefused::NOT_BOUND, 'the package is not bound to a declared source');
        }
    }

    /**
     * Take the package lease. Every step is idempotent, so a newer projection may
     * take over a lease; the older one notices at its next step and stops.
     */
    private function claim(PhrEhiPackage $package, int $actorUserId, string $token, string $fingerprint): void
    {
        $this->writeGuard->run((int) $package->patient_id, function (PhrPatient $patient) use ($package, $actorUserId, $token, $fingerprint): void {
            $this->access->ensureCanWrite($patient, $actorUserId);
            $current = PhrEhiPackage::query()->whereKey($package->id)->where('patient_id', $patient->id)->lockForUpdate()->firstOrFail();
            $this->ensureReady($current);

            $checkpoint = $current->checkpoint_json ?? [];
            $projection = $checkpoint['projection'] ?? [];
            // Finished tables are only reused by a resumed run of the same mappings.
            if (($projection['registry'] ?? null) !== $fingerprint || $current->clinical_status === PhrEhiPackage::CLINICAL_PROJECTED) {
                $projection = ['registry' => $fingerprint, 'version' => self::VERSION, 'tables' => []];
            }
            $checkpoint['projection'] = $projection;

            $current->forceFill([
                'clinical_status' => PhrEhiPackage::CLINICAL_PROJECTING,
                'failure_category' => null,
                'lease_token' => $token,
                'lease_expires_at' => $this->leaseExpiry(now()),
                'checkpoint_json' => $checkpoint,
            ])->save();
        });
    }

    /**
     * The package dictionary, re-read from its stored, digest-verified bytes in
     * the same order intake read it.
     */
    private function dictionary(PhrEhiPackage $package, EhiLimits $limits): EhiDictionary
    {
        $members = PhrEhiPackageMember::query()->where('package_id', $package->id)
            ->whereIn('kind', [EhiMemberKind::DICTIONARY, EhiMemberKind::INDEX])->get()
            ->sort(static fn (PhrEhiPackageMember $a, PhrEhiPackageMember $b): int => strcmp($a->path, $b->path))
            ->values();

        $documents = function () use ($members, $limits): Generator {
            foreach ($members as $member) {
                if ($member->byte_size > $limits->maxDictionaryBytes) {
                    continue;
                }
                $temp = $this->stage($member);
                try {
                    yield (string) stream_get_contents($temp);
                } finally {
                    fclose($temp);
                }
            }
        };

        return EhiDictionaryReader::fromLimits($limits)->read($documents());
    }

    /** @return array<string, PhrEhiPackageMember> table members keyed by upper-cased table name */
    private function tableMembers(PhrEhiPackage $package): array
    {
        $members = [];
        PhrEhiPackageMember::query()->where('package_id', $package->id)
            ->where('kind', EhiMemberKind::TABLE)->whereNotNull('table_name')->orderBy('id')
            ->each(function (PhrEhiPackageMember $member) use (&$members): void {
                $members[strtoupper((string) $member->table_name)] = $member;
            });

        return $members;
    }

    /**
     * @param  list<string>  $key
     * @return self::TABLE_*
     */
    private function projectTable(PhrEhiPackage $package, PhrEhiPackageMember $member, string $table, array $key, EhiDictionary $dictionary, EhiLimits $limits, string $token, int $actorUserId): string
    {
        $account = PhrEhiTableAccount::query()->where('package_id', $package->id)->where('member_id', $member->id)->first();
        $header = $account?->header_columns_json;
        if ($account === null || $header === null) {
            return self::TABLE_ABSENT;
        }

        $problem = $this->keyProblem($dictionary, $member, $key, $header);
        if ($problem !== null) {
            $this->underLease($package, $token, $actorUserId, function (PhrPatient $patient) use ($package, $member, $table, $problem): void {
                $this->clearFindings($member);
                $now = now();
                $rows = [];
                foreach ($this->registry->all() as $name => $mapping) {
                    if (isset($mapping->expectedKeys()[$table])) {
                        $rows[] = $this->findingRow($package, $member, EhiFindingCode::MAPPING_KEY_MISMATCH, null, null, ['mapping' => $name, 'problem' => $problem], $now);
                    }
                }
                PhrEhiFinding::query()->insert($rows);
            });

            return self::TABLE_MISMATCHED;
        }

        $dialect = EhiDialect::provisional();
        if ($account->parser_version !== $dialect->version) {
            throw new EhiProjectionRefused(EhiProjectionRefused::DIALECT_CHANGED, 'a table was accounted under another dialect');
        }

        $source = (int) $package->source_id;
        $fingerprint = $dictionary->isEmpty() ? null : $dictionary->fingerprint();
        $this->underLease($package, $token, $actorUserId, fn () => $this->clearFindings($member));

        $temp = $this->stage($member);
        try {
            $reader = new EhiTsvReader($temp, $dialect, $limits);
            $columns = $reader->header() ?? [];
            if ($columns !== $header) {
                throw new EhiProjectionRefused(EhiProjectionRefused::ACCOUNTING_MISMATCH, 'a table header differs from its account');
            }
            $keyIndexes = array_map(static fn (string $column): int => (int) array_search($column, $columns, true), $key);

            $budget = $limits->maxFindingsPerMember;
            $recordBytes = intdiv((int) config('phr.native_restore_max_record_bytes'), 4);
            $omitted = 0;
            $rows = 0;
            $malformed = 0;
            $chunk = [];
            $findings = [];
            foreach ($reader->rows() as $row) {
                $rows++;
                if ($row instanceof EhiTsvMalformedRow) {
                    $malformed++;

                    continue;
                }

                $keyValues = [];
                $complete = true;
                foreach ($key as $position => $column) {
                    $value = $row->values[$keyIndexes[$position]] ?? null;
                    $complete = $complete && $value !== null && $value !== '';
                    $keyValues[$column] = $value;
                }
                $values = $complete ? array_combine($columns, $row->values) : [];
                $tooLarge = $complete
                    && strlen(json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > $recordBytes;
                if (! $complete || $tooLarge) {
                    if ($budget > 0) {
                        $budget--;
                        $findings[] = [$complete ? EhiFindingCode::REVISION_TOO_LARGE : EhiFindingCode::MISSING_KEY, $row->lineNumber, $row->byteOffset, []];
                    } else {
                        $omitted++;
                    }
                } else {
                    $chunk[] = [
                        'line' => $row->lineNumber,
                        'offset' => $row->byteOffset,
                        'key' => $keyValues,
                        'key_digest' => EhiMappingContext::keyDigest($keyValues),
                        'values' => $values,
                        'content_digest' => EhiCanonicalJson::digest($values),
                    ];
                }

                if (count($chunk) + count($findings) >= self::CHUNK_ROWS) {
                    $this->flush($package, $member, $source, $table, $fingerprint, $chunk, $findings, $token, $actorUserId);
                    $chunk = [];
                    $findings = [];
                }
            }
            $this->flush($package, $member, $source, $table, $fingerprint, $chunk, $findings, $token, $actorUserId);

            // The account was written from the same bytes; any difference means
            // the reader changed, so the counts below would no longer reconcile.
            if ($rows !== $account->source_row_count || $malformed !== $account->malformed_row_count) {
                throw new EhiProjectionRefused(EhiProjectionRefused::ACCOUNTING_MISMATCH, 'a table no longer reads as accounted');
            }
        } finally {
            fclose($temp);
        }

        $this->underLease($package, $token, $actorUserId, function () use ($package, $member, $account, $budget, $omitted): void {
            $this->finishTable($package, $member, $account, $budget, $omitted);
        });

        return self::TABLE_PROJECTED;
    }

    /**
     * Why a mapped table cannot be projected, as a fixed code, or null.
     *
     * @param  list<string>  $key
     * @param  list<string>  $header
     */
    private function keyProblem(EhiDictionary $dictionary, PhrEhiPackageMember $member, array $key, array $header): ?string
    {
        $name = (string) $member->table_name;
        $documented = $dictionary->table($name) ?? $dictionary->table(strtoupper($name));
        if ($documented === null) {
            return 'table_undocumented';
        }
        if ($documented->primaryKey === []) {
            return 'key_undocumented';
        }
        if ($documented->primaryKey !== $key) {
            return 'key_differs';
        }
        if (array_diff($key, $header) !== []) {
            return 'key_column_not_in_header';
        }

        return null;
    }

    /**
     * Write one bounded chunk: entities, revisions, then occurrences, each an
     * insert-or-ignore on its unique key, followed by duplicate quarantine.
     *
     * @param  list<array{line: int, offset: int, key: array<string, string|null>, key_digest: string, values: array<string, string|null>, content_digest: string}>  $chunk
     * @param  list<array{0: string, 1: int, 2: int, 3: array<string, int|string|bool|null>}>  $findings
     */
    private function flush(PhrEhiPackage $package, PhrEhiPackageMember $member, int $source, string $table, ?string $fingerprint, array $chunk, array $findings, string $token, int $actorUserId): void
    {
        if ($chunk === [] && $findings === []) {
            return;
        }

        $this->underLease($package, $token, $actorUserId, function (PhrPatient $patient) use ($package, $member, $source, $table, $fingerprint, $chunk, $findings): void {
            $now = now();
            $json = static fn (array $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ($findings !== []) {
                PhrEhiFinding::query()->insert(array_map(
                    fn (array $finding): array => $this->findingRow($package, $member, $finding[0], $finding[1], $finding[2], $finding[3], $now),
                    $findings,
                ));
            }
            if ($chunk === []) {
                return;
            }

            $entities = [];
            foreach ($chunk as $row) {
                $entities[$row['key_digest']] ??= [
                    'patient_id' => $patient->id,
                    'source_id' => $source,
                    'table_name' => $table,
                    'key_digest' => $row['key_digest'],
                    'key_json' => $json($row['key']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            PhrEhiEntity::query()->insertOrIgnore(array_values($entities));
            $entityIds = PhrEhiEntity::query()->where('source_id', $source)->where('table_name', $table)
                ->whereIn('key_digest', array_keys($entities))->pluck('id', 'key_digest');

            $revisions = [];
            foreach ($chunk as $row) {
                $entityId = (int) $entityIds[$row['key_digest']];
                $revisions[$entityId.':'.$row['content_digest']] ??= [
                    'patient_id' => $patient->id,
                    'entity_id' => $entityId,
                    'first_package_id' => $package->id,
                    'content_digest' => $row['content_digest'],
                    'values_json' => $json($row['values']),
                    'dictionary_fingerprint' => $fingerprint,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            PhrEhiRevision::query()->insertOrIgnore(array_values($revisions));
            $revisionIds = [];
            PhrEhiRevision::query()->whereIn('entity_id', $entityIds->values()->all())
                ->whereIn('content_digest', array_unique(array_column($chunk, 'content_digest')))
                ->get(['id', 'entity_id', 'content_digest'])
                ->each(function (PhrEhiRevision $revision) use (&$revisionIds): void {
                    $revisionIds[$revision->entity_id.':'.$revision->content_digest] = $revision->id;
                });

            // A key repeated inside this chunk, or already seen elsewhere in this
            // member, is ambiguous: which row is the entity cannot be decided.
            $seen = [];
            foreach ($chunk as $row) {
                $entityId = (int) $entityIds[$row['key_digest']];
                $seen[$entityId] = ($seen[$entityId] ?? 0) + 1;
            }
            $duplicates = array_keys(array_filter($seen, static fn (int $count): bool => $count > 1));
            $elsewhere = PhrEhiOccurrence::query()
                ->join('phr_ehi_revisions', 'phr_ehi_revisions.id', '=', 'phr_ehi_occurrences.revision_id')
                ->where('phr_ehi_occurrences.member_id', $member->id)
                ->whereIn('phr_ehi_revisions.entity_id', array_keys($seen))
                ->whereNotIn('phr_ehi_occurrences.line_number', array_column($chunk, 'line'))
                ->distinct()->pluck('phr_ehi_revisions.entity_id')->map(static fn (mixed $id): int => (int) $id)->all();
            $duplicates = array_flip([...$duplicates, ...$elsewhere]);

            $linked = PhrEhiRecordLink::query()->whereIn('revision_id', array_values($revisionIds))
                ->distinct()->pluck('revision_id')->flip();

            $occurrences = [];
            foreach ($chunk as $row) {
                $entityId = (int) $entityIds[$row['key_digest']];
                $revisionId = $revisionIds[$entityId.':'.$row['content_digest']];
                $occurrences[] = [
                    'patient_id' => $patient->id,
                    'package_id' => $package->id,
                    'member_id' => $member->id,
                    'revision_id' => $revisionId,
                    'line_number' => $row['line'],
                    'byte_offset' => $row['offset'],
                    'disposition' => match (true) {
                        isset($duplicates[$entityId]) => PhrEhiOccurrence::DISPOSITION_QUARANTINED,
                        $linked->has($revisionId) => PhrEhiOccurrence::DISPOSITION_CORROBORATING,
                        default => PhrEhiOccurrence::DISPOSITION_PENDING_REVIEW,
                    },
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            PhrEhiOccurrence::query()->insertOrIgnore($occurrences);

            if ($duplicates !== []) {
                PhrEhiOccurrence::query()->where('member_id', $member->id)
                    ->whereIn('revision_id', PhrEhiRevision::query()->select('id')->whereIn('entity_id', array_keys($duplicates)))
                    ->update(['disposition' => PhrEhiOccurrence::DISPOSITION_QUARANTINED, 'updated_at' => $now]);
            }
        });
    }

    /**
     * Record one duplicate_key finding per quarantined repeated-key occurrence,
     * within the remaining finding budget, and reconcile the table account.
     */
    private function finishTable(PhrEhiPackage $package, PhrEhiPackageMember $member, PhrEhiTableAccount $account, int $budget, int $omitted): void
    {
        $now = now();
        $repeated = PhrEhiOccurrence::query()
            ->join('phr_ehi_revisions', 'phr_ehi_revisions.id', '=', 'phr_ehi_occurrences.revision_id')
            ->where('phr_ehi_occurrences.member_id', $member->id)
            ->groupBy('phr_ehi_revisions.entity_id')
            ->havingRaw('count(*) > 1')
            ->pluck('phr_ehi_revisions.entity_id');
        $rows = [];
        if ($repeated->isNotEmpty()) {
            PhrEhiOccurrence::query()
                ->select('phr_ehi_occurrences.line_number', 'phr_ehi_occurrences.byte_offset')
                ->join('phr_ehi_revisions', 'phr_ehi_revisions.id', '=', 'phr_ehi_occurrences.revision_id')
                ->where('phr_ehi_occurrences.member_id', $member->id)
                ->whereIn('phr_ehi_revisions.entity_id', $repeated->all())
                ->orderBy('phr_ehi_occurrences.line_number')
                ->each(function (PhrEhiOccurrence $occurrence) use ($package, $member, $now, &$budget, &$omitted, &$rows): void {
                    if ($budget <= 0) {
                        $omitted++;

                        return;
                    }
                    $budget--;
                    $rows[] = $this->findingRow($package, $member, EhiFindingCode::DUPLICATE_KEY, $occurrence->line_number, $occurrence->byte_offset, [], $now);
                });
        }
        if ($omitted > 0) {
            $rows[] = $this->findingRow($package, $member, EhiFindingCode::FINDINGS_TRUNCATED, null, null, ['omitted' => $omitted], $now);
        }
        foreach (array_chunk($rows, self::CHUNK_ROWS) as $batch) {
            PhrEhiFinding::query()->insert($batch);
        }

        EhiDispositionCounter::recount($account);
    }

    /** Remove this stage's findings for one member, so a re-run re-emits them exactly once. */
    private function clearFindings(PhrEhiPackageMember $member): void
    {
        PhrEhiFinding::query()->where('member_id', $member->id)->where('detail_json->stage', self::FINDING_STAGE)->delete();
    }

    /**
     * @param  array<string, int|string|bool|null>  $detail
     * @return array<string, mixed>
     */
    private function findingRow(PhrEhiPackage $package, PhrEhiPackageMember $member, string $code, ?int $line, ?int $offset, array $detail, Carbon $now): array
    {
        return [
            'patient_id' => $package->patient_id,
            'package_id' => $package->id,
            'member_id' => $member->id,
            'code' => $code,
            'severity' => EhiFindingCode::SEVERITY[$code],
            'line_number' => $line,
            'byte_offset' => $offset,
            'column_name' => null,
            'detail_json' => json_encode(['stage' => self::FINDING_STAGE, ...$detail], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Copy a stored member into a private temp file, hashing as it goes. Bytes
     * that differ from the manifest are never parsed.
     *
     * @return resource
     */
    private function stage(PhrEhiPackageMember $member)
    {
        $blob = PhrEhiBlob::query()->whereKey($member->blob_id)->where('patient_id', $member->patient_id)->first();
        if ($blob === null) {
            throw new EhiProjectionRefused(EhiProjectionRefused::BLOB_UNVERIFIED, 'a member has no stored blob');
        }
        $temp = tmpfile();
        if (! is_resource($temp)) {
            throw new EhiProjectionRefused(EhiProjectionRefused::BLOB_UNVERIFIED, 'a staging file could not be created');
        }
        try {
            $stream = Storage::disk($blob->storage_disk)->readStream($blob->storage_path);
            if (! is_resource($stream)) {
                throw new EhiProjectionRefused(EhiProjectionRefused::BLOB_UNVERIFIED, 'a stored member is missing');
            }
            try {
                $copied = $this->archiveGuard->copyBounded($stream, $temp, $member->byte_size, $member->byte_size);
            } finally {
                fclose($stream);
            }
        } catch (EhiProjectionRefused $refused) {
            fclose($temp);
            throw $refused;
        } catch (Throwable $failure) {
            fclose($temp);
            throw new EhiProjectionRefused(EhiProjectionRefused::BLOB_UNVERIFIED, 'a stored member could not be read', $failure);
        }

        if ($copied['bytes'] !== $member->byte_size || ! hash_equals($member->sha256, $copied['sha256'])) {
            fclose($temp);
            throw new EhiProjectionRefused(EhiProjectionRefused::BLOB_UNVERIFIED, 'a stored member no longer matches its manifest digest');
        }
        rewind($temp);

        return $temp;
    }

    /**
     * Run one bounded step under the patient lock while still holding the lease,
     * re-checking write access and renewing the lease.
     *
     * @template TResult
     *
     * @param  callable(PhrPatient, PhrEhiPackage): TResult  $step
     * @return TResult
     */
    private function underLease(PhrEhiPackage $package, string $token, int $actorUserId, callable $step): mixed
    {
        return $this->writeGuard->run((int) $package->patient_id, function (PhrPatient $patient) use ($package, $token, $actorUserId, $step): mixed {
            $this->access->ensureCanWrite($patient, $actorUserId);
            $current = PhrEhiPackage::query()->whereKey($package->id)->where('patient_id', $patient->id)->lockForUpdate()->first();
            if ($current === null) {
                throw (new ModelNotFoundException)->setModel(PhrEhiPackage::class, [$package->id]);
            }
            if ($current->lease_token !== $token) {
                throw new EhiProjectionRefused(EhiProjectionRefused::LEASE_LOST, 'another worker holds this package lease');
            }
            $current->lease_expires_at = $this->leaseExpiry(now());
            $result = $step($patient, $current);
            $current->save();

            return $result;
        });
    }

    /** Best effort: mark the projection failed and release the lease. A lost lease belongs to someone else. */
    private function recordFailure(PhrEhiPackage $package, string $token, Throwable $failure): void
    {
        if ($failure instanceof ModelNotFoundException
            || ($failure instanceof EhiProjectionRefused && $failure->reason === EhiProjectionRefused::LEASE_LOST)) {
            return;
        }
        $category = match (true) {
            $failure instanceof EhiProjectionRefused => $failure->reason,
            $failure instanceof AuthorizationException => self::FAILURE_ACCESS_REVOKED,
            default => self::FAILURE_UNEXPECTED,
        };

        try {
            $this->writeGuard->run((int) $package->patient_id, function () use ($package, $token, $category): void {
                $current = PhrEhiPackage::query()->whereKey($package->id)->lockForUpdate()->first();
                if ($current === null || $current->lease_token !== $token) {
                    return;
                }
                $current->forceFill([
                    'clinical_status' => PhrEhiPackage::CLINICAL_FAILED,
                    'failure_category' => $category,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                ])->save();
            });
        } catch (Throwable) {
            // Preserve the original failure; an unreleased lease simply expires.
        }
    }

    /** @return array<string, mixed> */
    private function checkpoint(PhrEhiPackage $package): array
    {
        $current = PhrEhiPackage::query()->whereKey($package->id)->value('checkpoint_json');
        $decoded = is_string($current) ? json_decode($current, true) : $current;

        return is_array($decoded) && is_array($decoded['projection'] ?? null) ? $decoded['projection'] : [];
    }

    private function result(PhrEhiPackage $package, bool $replayed): EhiProjectionResult
    {
        $tables = $package->checkpoint_json['projection']['tables'] ?? [];
        $tables = is_array($tables) ? $tables : [];
        $outcomes = array_count_values(array_map('strval', $tables));
        $projected = [];
        foreach ($tables as $table => $outcome) {
            if ($outcome === self::TABLE_PROJECTED) {
                $projected[strtoupper((string) $table)] = true;
            }
        }
        $dispositions = array_fill_keys(EhiDispositionCounter::DISPOSITIONS, 0);
        PhrEhiTableAccount::query()->where('package_id', $package->id)->whereNotNull('member_id')->orderBy('id')
            ->each(function (PhrEhiTableAccount $account) use ($projected, &$dispositions): void {
                if (! isset($projected[strtoupper($account->table_name)])) {
                    return;
                }
                foreach ($account->disposition_counts_json ?? [] as $disposition => $count) {
                    $dispositions[$disposition] = ($dispositions[$disposition] ?? 0) + (int) $count;
                }
            });

        return new EhiProjectionResult(
            $package,
            $replayed,
            $outcomes[self::TABLE_PROJECTED] ?? 0,
            $outcomes[self::TABLE_MISMATCHED] ?? 0,
            $outcomes[self::TABLE_ABSENT] ?? 0,
            $dispositions,
        );
    }

    private function leaseExpiry(Carbon $now): Carbon
    {
        return $now->copy()->addMinutes((int) config('phr.ehi_lease_minutes'));
    }
}
