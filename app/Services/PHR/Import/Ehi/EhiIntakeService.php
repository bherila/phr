<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiBlob;
use App\Models\PhrEhiFinding;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiTableAccount;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Support\Storage\PhrStorageKey;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * The single application service for EHI Tables intake (issue #214). It preserves
 * and accounts for a package; it never writes clinical data.
 *
 * 1. Validate the entry list, classify members, and refuse anything that is not
 *    an EHI Tables package (an unsupported export never looks like an empty import).
 * 2. First pass, nothing written: hash every member, read the dictionary, parse
 *    every table into in-memory accounting, and collect the distinct `PAT_ID`
 *    values. More than one, or none, is refused before any byte is stored, as is
 *    a malformed row or a blank `PAT_ID` in a table with a `PAT_ID` column (its
 *    patient is unknown).
 * 3. Under the patient artifact guard, claim the package by manifest digest:
 *    replay a complete one, resume an incomplete one (or one accounted under
 *    other rule versions, which is re-accounted), or create it with a lease.
 *    A replay or resume first re-verifies the package's blobs and republishes any
 *    missing or damaged object from the source bytes (never an intact one).
 * 4. Second pass: each member in its own short guard transaction, re-hashed while
 *    staged and re-read after publishing. A blob shared with another package is
 *    verified, never rewritten while intact. A failed step deletes only an object
 *    it wrote that no blob row references.
 * 5. Mark preserved, then write all accounting in one transaction and drop the lease.
 *
 * Exceptions carry fixed codes and structural messages only: never a member name,
 * a table or column name, or a cell value.
 *
 * @phpstan-type Finding array{code: string, line: ?int, offset: ?int, column: ?string, detail: array<string, int|string|bool|null>|null}
 * @phpstan-type FindingList array{list: list<Finding>, omitted: int}
 * @phpstan-type Classified array{member: EhiValidatedMember, kind: string, table: ?string, unsupported_name: bool, table_shaped: bool, first_line: ?string}
 * @phpstan-type MemberPlan array{member: EhiValidatedMember, kind: string, table: ?string, unsupported_name: bool, table_shaped: bool, first_line: ?string, sha256: string, bytes: int}
 * @phpstan-type AccountPlan array{path: string, table: string, state: string, header: ?list<string>, unknown: ?list<string>, missing: ?list<string>, rows: int, malformed: int, disposition: string}
 * @phpstan-type Plan array{members: list<MemberPlan>, accounts: list<AccountPlan>, missing: list<string>, findings: array<string, FindingList>, package_findings: list<Finding>, patient_key: string, dictionary: EhiDictionary, has_dictionary: bool}
 */
final class EhiIntakeService
{
    /** Two table members share a table name (case-insensitively). */
    public const string REJECT_DUPLICATE_TABLE = 'duplicate_table';

    /**
     * A row in a table that has (or may have) a `PAT_ID` column whose patient
     * cannot be established -- malformed, or with a blank or null `PAT_ID` -- so
     * it could belong to another patient.
     */
    public const string REJECT_UNSCOPED_PATIENT_ROW = 'unscoped_patient_row';

    /**
     * Re-accounting a package under changed rules would detect a different
     * source patient key than the one it was recorded (and perhaps bound) with.
     */
    public const string REJECT_PATIENT_KEY_CHANGED = 'source_patient_key_changed';

    /**
     * A table's header, unknown, and missing column lists together are too large
     * for one native backup record (a quarter of the restore reader's record
     * limit, leaving room for escaping), so its account could be backed up but
     * never restored.
     */
    public const string REJECT_ACCOUNT_COLUMNS_TOO_LARGE = 'account_columns_too_large';

    /** The source patient key is longer than a package or source can record. */
    public const string REJECT_PATIENT_KEY_TOO_LONG = 'source_patient_key_too_long';

    public const string FAILURE_ACCESS_REVOKED = 'access_revoked';

    public const string FAILURE_UNEXPECTED = 'unexpected';

    /** The column whose distinct values identify the source patient. */
    public const string PATIENT_KEY_COLUMN = 'PAT_ID';

    private const int MAX_NAME_BYTES = 128;

    private const int FIRST_LINE_BYTES = 64 * 1024;

    private const int INSERT_CHUNK = 500;

    private const array PEEK_EXTENSIONS = ['tsv', 'txt', 'htm', 'html'];

    public function __construct(
        private readonly PhrPatientArtifactWriteGuard $writeGuard,
        private readonly PhrPatientAccessService $access,
        private readonly EhiArchiveGuard $archiveGuard,
        private readonly EhiMemberClassifier $classifier,
        private readonly EhiPackageDetector $detector,
        private readonly EhiBlobVerifier $verifier,
    ) {}

    /**
     * @throws EhiPackageRejected refused before anything was stored
     * @throws EhiIntakeFailed stopped after the package row existed; a retry resumes
     * @throws AuthorizationException the actor cannot (or can no longer) write this patient
     * @throws ModelNotFoundException the patient no longer exists
     */
    public function intake(EhiPackageSource $source, PhrPatient $patient, int $actorUserId): EhiIntakeResult
    {
        $this->access->ensureCanWrite($patient, $actorUserId);
        $limits = EhiLimits::fromConfig();
        $dialect = EhiDialect::provisional();

        $validated = $this->archiveGuard->validate($source, $limits);
        $classified = $this->classify($source, $validated);
        $plan = $this->firstPass($source, $classified, $limits, $dialect);
        $original = $source->originalArchive($limits);

        $token = (string) Str::uuid();
        [$package, $outcome] = $this->claim($source, $patient, $actorUserId, $plan, $original, $token, $dialect);
        if ($outcome === 'replayed') {
            $repaired = $this->repair($source, $package, $plan, $limits, $actorUserId);

            return new EhiIntakeResult($package, true, false, EhiPackageSummary::forPackage($package), $repaired);
        }

        try {
            $repaired = $outcome === 'resumed' ? $this->repair($source, $package, $plan, $limits, $actorUserId) : 0;
            $repaired += $this->preserve($source, $package, $plan, $limits, $token, $actorUserId);
            $this->account($package, $plan, $dialect, $token, $actorUserId);
        } catch (Throwable $failure) {
            $this->recordFailure($package, $token, $failure);

            throw $failure;
        }

        $package->refresh();

        return new EhiIntakeResult($package, false, $outcome === 'resumed', EhiPackageSummary::forPackage($package), $repaired);
    }

    /**
     * @param  list<EhiValidatedMember>  $members
     * @return list<Classified>
     */
    private function classify(EhiPackageSource $source, array $members): array
    {
        $firstLines = [];
        $classified = [];
        foreach ($members as $member) {
            $extension = strtolower(pathinfo($member->path, PATHINFO_EXTENSION));
            $line = in_array($extension, self::PEEK_EXTENSIONS, true) ? $this->firstLine($source, $member) : null;
            $firstLines[$member->path] = $line;
            $class = $this->classifier->classify($member->path, $line);
            $classified[] = ['member' => $member, 'kind' => $class['kind'], 'table' => $class['table'], 'unsupported_name' => isset($class['unsupported_table_name']), 'table_shaped' => EhiMemberClassifier::isTableShaped($member->path, $line), 'first_line' => $line];
        }

        $detection = $this->detector->detect($members, static fn (EhiValidatedMember $member): ?string => $firstLines[$member->path] ?? null);
        if (! $detection->isEhi) {
            throw new EhiPackageRejected(EhiPackageRejected::NOT_EHI, 'EHI package refused (not_ehi): the package is not recognized as an EHI Tables export.');
        }

        // Accounting is keyed by table name; a second member claiming a name (in
        // any case, which a case-insensitive collation would merge) cannot be
        // accounted without inventing a name, so it is refused, not dropped. The
        // classifier admits only ASCII identifiers (at most MAX_NAME_BYTES), so
        // ASCII case folding here matches the collation.
        $tables = [];
        foreach ($classified as $item) {
            if ($item['kind'] !== EhiMemberKind::TABLE || $item['table'] === null) {
                continue;
            }
            $key = strtoupper($item['table']);
            if (isset($tables[$key])) {
                throw new EhiPackageRejected(self::REJECT_DUPLICATE_TABLE, sprintf('EHI package refused (%s): two table members share a table name.', self::REJECT_DUPLICATE_TABLE));
            }
            $tables[$key] = true;
        }

        return $classified;
    }

    /**
     * Pass 1. Reads only; any refusal here happens before a single row or byte is stored.
     *
     * @param  list<Classified>  $classified
     * @return Plan
     */
    private function firstPass(EhiPackageSource $source, array $classified, EhiLimits $limits, EhiDialect $dialect): array
    {
        $members = [];
        foreach ($classified as $item) {
            $measured = $this->hashMember($source, $item['member'], $limits);
            // Classification and detection read the first line separately; it
            // must be the first line of the bytes now hashed, or the member's
            // kind and table would describe bytes that are not the evidence.
            if ($item['first_line'] !== null && $this->lineOf($measured['prefix']) !== $item['first_line']) {
                throw new EhiPackageRejected(EhiPackageRejected::SIZE_MISMATCH, 'EHI package refused (size_mismatch): a member changed while it was being read.');
            }
            $members[] = [...$item, 'sha256' => $measured['sha256'], 'bytes' => $measured['bytes']];
        }

        /** @var array<string, FindingList> $findings */
        $findings = [];
        $packageFindings = [];
        $hasDictionary = false;
        $documents = function () use ($source, $members, $limits, &$findings, &$hasDictionary): Generator {
            foreach ($members as $member) {
                if ($member['kind'] !== EhiMemberKind::DICTIONARY && $member['kind'] !== EhiMemberKind::INDEX) {
                    continue;
                }
                $hasDictionary = $hasDictionary || $member['kind'] === EhiMemberKind::DICTIONARY;
                if ($member['bytes'] > $limits->maxDictionaryBytes) {
                    $this->addFinding($findings, $member['member']->path, $limits, EhiFindingCode::DICTIONARY_OVERSIZE, detail: ['limit_bytes' => $limits->maxDictionaryBytes]);

                    continue;
                }

                yield $this->readDocument($source, $member);
            }
        };
        $dictionary = EhiDictionaryReader::fromLimits($limits)->read($documents());
        if ($dictionary->isEmpty()) {
            $packageFindings[] = $this->finding(EhiFindingCode::DICTIONARY_EMPTY, detail: ['documents' => $dictionary->documents]);
        }

        $patientKey = null;
        $accounts = [];
        $supplied = [];
        foreach ($members as $member) {
            $path = $member['member']->path;
            if ($member['kind'] === EhiMemberKind::UNKNOWN) {
                $this->addFinding($findings, $path, $limits, $member['unsupported_name'] ? EhiFindingCode::UNSUPPORTED_TABLE_NAME : EhiFindingCode::UNKNOWN_MEMBER);
            }
            if ($member['table_shaped'] && $member['kind'] !== EhiMemberKind::TABLE) {
                // Not accounted as a table (an unsupported name, a readme-like
                // basename), but table-shaped: its rows are patient-scoped like any
                // table's, so another patient's PAT_ID (or a blank or malformed
                // one) refuses the package. Only that check is kept; its
                // accounting and findings are discarded.
                $scratch = [];
                $this->accountTable($source, $member, $dictionary, $limits, $dialect, $scratch, $patientKey);

                continue;
            }
            if ($member['kind'] !== EhiMemberKind::TABLE || $member['table'] === null) {
                continue;
            }
            $supplied[strtoupper($member['table'])] = true;
            $accounts[] = $this->accountTable($source, $member, $dictionary, $limits, $dialect, $findings, $patientKey);
        }

        if ($patientKey === null) {
            throw new EhiPackageRejected(EhiPackageRejected::NO_PATIENT, sprintf('EHI package refused (no_patient): no table supplies a %s value.', self::PATIENT_KEY_COLUMN));
        }

        // Dictionary names are ASCII identifiers too; folded, so two spellings of
        // one table cannot collide on the case-insensitive account key.
        $missing = [];
        foreach (array_keys($dictionary->tables) as $name) {
            $key = strtoupper((string) $name);
            if (! isset($supplied[$key]) && strlen((string) $name) <= self::MAX_NAME_BYTES) {
                $missing[] = (string) $name;
                $supplied[$key] = true;
            }
        }

        return [
            'members' => $members,
            'accounts' => $accounts,
            'missing' => $missing,
            'findings' => $findings,
            'package_findings' => $packageFindings,
            'patient_key' => $patientKey,
            'dictionary' => $dictionary,
            'has_dictionary' => $hasDictionary,
        ];
    }

    /**
     * @param  MemberPlan  $member
     * @param  array<string, FindingList>  $findings
     * @return AccountPlan
     */
    private function accountTable(EhiPackageSource $source, array $member, EhiDictionary $dictionary, EhiLimits $limits, EhiDialect $dialect, array &$findings, ?string &$patientKey): array
    {
        $path = $member['member']->path;
        $table = (string) $member['table'];
        $stream = $source->open($member['member']->entry);
        try {
            // Bounded by the hashed size: a member growing in place is read at
            // most one byte past it, and then fails the digest check below.
            $reader = new EhiTsvReader($stream, $dialect, $limits, $member['bytes']);
            $state = $reader->state();
            $header = $reader->header();
            if ($reader->headerProblem() !== null) {
                $this->addFinding($findings, $path, $limits, EhiFindingCode::MALFORMED_HEADER, detail: ['problem' => $reader->headerProblem()]);
            }

            $unknown = null;
            $missing = null;
            if ($header !== null && ! $dictionary->isEmpty()) {
                $documented = $dictionary->table($table);
                if ($documented === null) {
                    $this->addFinding($findings, $path, $limits, EhiFindingCode::UNKNOWN_TABLE);
                } else {
                    $known = array_flip($documented->columnNames());
                    $unknown = [];
                    foreach ($header as $index => $column) {
                        if (! isset($known[$column])) {
                            $unknown[] = $column;
                            $this->addFinding($findings, $path, $limits, EhiFindingCode::UNKNOWN_COLUMN,
                                column: strlen($column) <= self::MAX_NAME_BYTES ? $column : null,
                                detail: ['column_index' => $index]);
                        }
                    }
                    $missing = array_values(array_diff($documented->columnNames(), $header));
                }
            }

            $columnsBytes = strlen(json_encode([$header, $unknown, $missing], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($columnsBytes > intdiv((int) config('phr.native_restore_max_record_bytes'), 4)) {
                throw new EhiPackageRejected(self::REJECT_ACCOUNT_COLUMNS_TOO_LARGE, sprintf('EHI package refused (%s): a table\'s column lists are too large for a native backup record.', self::REJECT_ACCOUNT_COLUMNS_TOO_LARGE));
            }

            $keyIndex = $header === null ? false : array_search(self::PATIENT_KEY_COLUMN, $header, true);
            // Unknown (an unsplittable header) counts as scoped: it may have the column.
            $scoped = $reader->declaresColumn(self::PATIENT_KEY_COLUMN) !== false;
            $rows = 0;
            $malformed = 0;
            foreach ($reader->rows() as $row) {
                $rows++;
                if ($row instanceof EhiTsvMalformedRow) {
                    // Its PAT_ID cannot be read, so it might be another patient's
                    // row; refused rather than preserved under this patient.
                    if ($scoped) {
                        throw new EhiPackageRejected(self::REJECT_UNSCOPED_PATIENT_ROW, sprintf('EHI package refused (%s): a malformed row in a table with a %s column has no establishable patient.', self::REJECT_UNSCOPED_PATIENT_ROW, self::PATIENT_KEY_COLUMN));
                    }
                    $malformed++;
                    $this->addFinding($findings, $path, $limits, EhiFindingCode::MALFORMED_ROW,
                        line: $row->lineNumber, offset: $row->byteOffset,
                        detail: ['code' => $row->code, 'expected_fields' => $row->expectedFields, 'actual_fields' => $row->actualFields]);

                    continue;
                }
                if ($keyIndex !== false) {
                    $this->observePatientKey($row->values[$keyIndex] ?? null, $patientKey);
                }
            }

            // The bytes accounting was derived from (header problems included:
            // the reader drains them) must be exactly the bytes hashed into the
            // manifest; same length is not enough for a source changing in place.
            $read = $reader->digest();
            if ($read['bytes'] !== $member['bytes'] || ! hash_equals($member['sha256'], $read['sha256'])) {
                throw new EhiPackageRejected(EhiPackageRejected::SIZE_MISMATCH, 'EHI package refused (size_mismatch): a member changed while it was being read.');
            }
        } finally {
            fclose($stream);
        }

        return [
            'path' => $path,
            'table' => $table,
            'state' => $state,
            'header' => $header,
            'unknown' => $unknown,
            'missing' => $missing,
            'rows' => $rows,
            'malformed' => $malformed,
            'disposition' => EhiOutOfScopeTables::contains($table)
                ? PhrEhiTableAccount::DISPOSITION_OUT_OF_SCOPE
                : PhrEhiTableAccount::DISPOSITION_RETAINED_UNMAPPED,
        ];
    }

    /**
     * Exact string comparison: identifiers keep leading zeros, case, and
     * whitespace. A blank or null value is refused, not skipped: a row in a
     * patient-scoped table with no patient could be anyone's.
     *
     * @param-out string $patientKey
     */
    private function observePatientKey(?string $value, ?string &$patientKey): void
    {
        if ($value === null || $value === '') {
            throw new EhiPackageRejected(self::REJECT_UNSCOPED_PATIENT_ROW, sprintf('EHI package refused (%s): a row in a table with a %s column has a blank %s.', self::REJECT_UNSCOPED_PATIENT_ROW, self::PATIENT_KEY_COLUMN, self::PATIENT_KEY_COLUMN));
        }
        if ($patientKey === null) {
            if (strlen($value) > self::MAX_NAME_BYTES) {
                throw new EhiPackageRejected(self::REJECT_PATIENT_KEY_TOO_LONG, sprintf('EHI package refused (%s): the source patient key exceeds %d bytes.', self::REJECT_PATIENT_KEY_TOO_LONG, self::MAX_NAME_BYTES));
            }
            $patientKey = $value;

            return;
        }
        if ($value !== $patientKey) {
            throw new EhiPackageRejected(EhiPackageRejected::MULTIPLE_PATIENTS, sprintf('EHI package refused (multiple_patients): %s holds more than one distinct value.', self::PATIENT_KEY_COLUMN));
        }
    }

    /**
     * @param  Plan  $plan
     * @param  array{sha256: string, bytes: int}|null  $original
     * @return array{0: PhrEhiPackage, 1: 'created'|'resumed'|'replayed'}
     */
    private function claim(EhiPackageSource $source, PhrPatient $patient, int $actorUserId, array $plan, ?array $original, string $token, EhiDialect $dialect): array
    {
        $manifest = array_map(static fn (array $member): array => [
            'path' => $member['member']->path,
            'bytes' => $member['bytes'],
            'sha256' => $member['sha256'],
        ], $plan['members']);
        $digest = $this->archiveGuard->manifestDigest($manifest);

        return $this->writeGuard->run((int) $patient->id, function (PhrPatient $locked) use ($source, $actorUserId, $plan, $original, $token, $dialect, $digest): array {
            $this->access->ensureCanWrite($locked, $actorUserId);
            $now = now();
            $package = PhrEhiPackage::query()
                ->where('patient_id', $locked->id)
                ->where('manifest_digest', $digest)
                ->lockForUpdate()
                ->first();

            // A member row lost with its blob row (the FK cascades) leaves the
            // manifest incomplete: preservation is resumed and re-creates it.
            $complete = $package !== null
                && PhrEhiPackageMember::query()->where('package_id', $package->id)->count() === count($plan['members']);
            if ($package !== null
                && $package->preservation_status === PhrEhiPackage::PRESERVATION_PRESERVED
                && $package->accounting_status === PhrEhiPackage::ACCOUNTING_ACCOUNTED) {
                // Complete and accounted under the current rules: a replay. Under
                // any other dialect, classifier, detector, dictionary reader, or
                // scope registry version, accounting is redone (a resume below).
                if ($complete && $this->sameVersions($package->checkpoint_json['versions'] ?? null, $this->versions($dialect))) {
                    $package->forceFill(['intake_count' => $package->intake_count + 1, 'last_intake_at' => $now])->save();

                    return [$package, 'replayed'];
                }
                if ($package->detected_source_patient_key !== $plan['patient_key']) {
                    throw new EhiPackageRejected(self::REJECT_PATIENT_KEY_CHANGED, sprintf('EHI package refused (%s): accounting under the current rules detects a different source patient key.', self::REJECT_PATIENT_KEY_CHANGED));
                }
            }

            // Every later step is idempotent, so a newer intake may take over a
            // lease; the older one notices at its next step and stops.
            $lease = [
                'lease_token' => $token,
                'lease_expires_at' => $this->leaseExpiry($now),
                'last_intake_at' => $now,
                'failure_category' => null,
            ];
            if ($package !== null) {
                $preserved = $complete && $package->preservation_status === PhrEhiPackage::PRESERVATION_PRESERVED;
                $package->forceFill([
                    ...$lease,
                    'intake_count' => $package->intake_count + 1,
                    'preservation_status' => $preserved ? PhrEhiPackage::PRESERVATION_PRESERVED : PhrEhiPackage::PRESERVATION_PRESERVING,
                    'accounting_status' => $preserved ? PhrEhiPackage::ACCOUNTING_ACCOUNTING : PhrEhiPackage::ACCOUNTING_PENDING,
                ])->save();

                return [$package, 'resumed'];
            }

            $dictionary = $plan['dictionary'];
            $package = PhrEhiPackage::query()->create([
                ...$lease,
                'patient_id' => $locked->id,
                'created_by_user_id' => $actorUserId,
                'manifest_digest' => $digest,
                'intake_kind' => $source->kind(),
                'original_archive_sha256' => $original['sha256'] ?? null,
                'original_archive_bytes' => $original['bytes'] ?? null,
                'member_count' => count($plan['members']),
                'total_bytes' => array_sum(array_column($plan['members'], 'bytes')),
                'intake_count' => 1,
                'detected_source_patient_key' => $plan['patient_key'],
                'dictionary_fingerprint' => $dictionary->isEmpty() ? null : $dictionary->fingerprint(),
                'dictionary_version' => $plan['has_dictionary'] ? $dictionary->readerVersion : null,
                'preservation_status' => PhrEhiPackage::PRESERVATION_PRESERVING,
                'accounting_status' => PhrEhiPackage::ACCOUNTING_PENDING,
                'clinical_status' => PhrEhiPackage::CLINICAL_NOT_STARTED,
                'binding_status' => PhrEhiPackage::BINDING_UNBOUND,
                'checkpoint_json' => [
                    'phase' => PhrEhiPackage::PRESERVATION_PRESERVING,
                    'members_total' => count($plan['members']),
                    'members_preserved' => 0,
                    'versions' => $this->versions($dialect),
                ],
            ]);

            return [$package, 'created'];
        });
    }

    /**
     * Pass 2. Members already in the manifest (an earlier, interrupted intake) are
     * skipped: a member row commits in the same transaction as its verified blob.
     *
     * @param  Plan  $plan
     * @return int damaged objects of existing blob rows that were republished
     */
    private function preserve(EhiPackageSource $source, PhrEhiPackage $package, array $plan, EhiLimits $limits, string $token, int $actorUserId): int
    {
        $repaired = 0;
        $done = PhrEhiPackageMember::query()->where('package_id', $package->id)->pluck('path_sha256')->flip();
        foreach ($plan['members'] as $member) {
            if (! $done->has(hash('sha256', $member['member']->path))) {
                $repaired += $this->preserveMember($source, $package, $member, $limits, $token, $actorUserId) ? 1 : 0;
            }
        }

        $this->underLease($package, $token, $actorUserId, function (PhrPatient $patient, PhrEhiPackage $current): void {
            $members = PhrEhiPackageMember::query()->where('package_id', $current->id)->count();
            if ($members !== $current->member_count) {
                throw new LogicException('EHI package manifest is incomplete after preservation.');
            }
            $current->forceFill([
                'preservation_status' => PhrEhiPackage::PRESERVATION_PRESERVED,
                'accounting_status' => PhrEhiPackage::ACCOUNTING_ACCOUNTING,
                'checkpoint_json' => [...($current->checkpoint_json ?? []), 'phase' => PhrEhiPackage::PRESERVATION_PRESERVED, 'members_preserved' => $members],
            ]);
        });

        return $repaired;
    }

    /**
     * @param  MemberPlan  $member
     * @return bool whether an existing blob row's damaged object was republished
     */
    private function preserveMember(EhiPackageSource $source, PhrEhiPackage $package, array $member, EhiLimits $limits, string $token, int $actorUserId): bool
    {
        $patientId = (int) $package->patient_id;
        $sha256 = $member['sha256'];
        $bytes = $member['bytes'];

        // A blob another package already preserved is verified here, outside the
        // lock (the lease keeps deletion away), so its bytes are not re-staged.
        $existing = PhrEhiBlob::query()->where('patient_id', $patientId)->where('sha256', $sha256)->first();
        $staged = null;
        if ($existing === null || $this->verifier->inspect($existing->storage_disk, $existing->storage_path, $sha256, $bytes) !== EhiBlobVerifier::OK) {
            $staged = $this->stage($source, $member, $limits);
        }

        $diskName = (string) config('phr.ehi_disk');
        $key = PhrStorageKey::ehiBlob($patientId, $sha256);
        $wrote = false;
        $rowExisted = false;
        try {
            $this->underLease($package, $token, $actorUserId, function (PhrPatient $patient, PhrEhiPackage $current) use ($source, $member, $limits, $sha256, $bytes, $diskName, $key, &$staged, &$wrote, &$rowExisted): void {
                $now = now();
                $blob = PhrEhiBlob::query()->where('patient_id', $patient->id)->where('sha256', $sha256)->lockForUpdate()->first();
                $rowExisted = $blob !== null;
                $disk = $blob->storage_disk ?? $diskName;
                $path = $blob->storage_path ?? $key;
                // The shared object verified outside the lock is checked again
                // here: if its row is gone or the object changed meanwhile,
                // nothing vouches for it, so the source bytes are staged now.
                $intact = $staged === null && $blob !== null && $this->verifier->inspect($disk, $path, $sha256, $bytes) === EhiBlobVerifier::OK;
                if (! $intact && $staged === null) {
                    $staged = $this->stage($source, $member, $limits);
                }

                // Never overwrite an intact object: a torn write would damage evidence.
                $intact = $intact || $this->verifier->inspect($disk, $path, $sha256, $bytes) === EhiBlobVerifier::OK;
                if (! $intact) {
                    $wrote = true;
                    $this->publish($disk, $path, $staged);
                    if ($this->verifier->inspect($disk, $path, $sha256, $bytes) !== EhiBlobVerifier::OK) {
                        throw new EhiIntakeFailed(EhiIntakeFailed::STORED_OBJECT_MISMATCH, 'a stored member did not read back as written');
                    }
                }

                if ($blob === null) {
                    $blob = PhrEhiBlob::query()->create([
                        'patient_id' => $patient->id,
                        'sha256' => $sha256,
                        'byte_size' => $bytes,
                        'storage_disk' => $disk,
                        'storage_path' => $path,
                        'verified_at' => $now,
                    ]);
                } else {
                    $blob->forceFill(['verified_at' => $now])->save();
                }

                $inserted = PhrEhiPackageMember::query()->insertOrIgnore([
                    'patient_id' => $patient->id,
                    'package_id' => $current->id,
                    'blob_id' => $blob->id,
                    'path' => $member['member']->path,
                    'path_sha256' => hash('sha256', $member['member']->path),
                    'original_path' => $member['member']->entry->originalPath,
                    'byte_size' => $bytes,
                    'sha256' => $sha256,
                    'kind' => $member['kind'],
                    'table_name' => $member['table'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                // Commits with the member row, so the count survives any crash exactly.
                $checkpoint = $current->checkpoint_json ?? [];
                $current->checkpoint_json = [...$checkpoint, 'members_preserved' => (int) ($checkpoint['members_preserved'] ?? 0) + $inserted];
            });
        } catch (Throwable $failure) {
            if ($wrote && ! $rowExisted) {
                $this->compensate($patientId, $sha256, $diskName, $key);
            }

            throw $failure;
        } finally {
            if (is_resource($staged)) {
                fclose($staged);
            }
        }

        return $wrote && $rowExisted;
    }

    /**
     * Re-verify every blob the package's manifest references and republish any
     * missing or damaged object from the source, whose bytes pass 1 hashed to the
     * manifest value. Bytes are staged outside the lock; each republish happens
     * under the patient guard after re-checking the row and the object, so an
     * object that is intact by then (or a row deleted meanwhile) is left alone.
     *
     * @param  Plan  $plan
     * @return int objects republished
     */
    private function repair(EhiPackageSource $source, PhrEhiPackage $package, array $plan, EhiLimits $limits, int $actorUserId): int
    {
        $bySha = [];
        foreach ($plan['members'] as $member) {
            $bySha[$member['sha256']] ??= $member;
        }
        $blobIds = PhrEhiPackageMember::query()->where('package_id', $package->id)->distinct()->pluck('blob_id');
        $blobs = PhrEhiBlob::query()->where('patient_id', $package->patient_id)->whereIn('id', $blobIds)->orderBy('id')->get();

        $repaired = 0;
        $intact = [];
        foreach ($blobs as $blob) {
            if ($this->verifier->inspect($blob->storage_disk, $blob->storage_path, $blob->sha256, $blob->byte_size) === EhiBlobVerifier::OK) {
                $intact[] = $blob->id;

                continue;
            }
            $member = $bySha[$blob->sha256] ?? null;
            if ($member === null || $member['bytes'] !== $blob->byte_size) {
                // The digest matched, so the manifest names exactly these blobs.
                throw new LogicException('EHI package blob is not in the supplied manifest.');
            }

            $staged = $this->stage($source, $member, $limits);
            try {
                $repaired += $this->writeGuard->run((int) $package->patient_id, function (PhrPatient $patient) use ($blob, $staged, $actorUserId): int {
                    $this->access->ensureCanWrite($patient, $actorUserId);
                    $current = PhrEhiBlob::query()->whereKey($blob->id)->where('patient_id', $patient->id)->lockForUpdate()->first();
                    if ($current === null) {
                        return 0;
                    }
                    $verify = fn (): string => $this->verifier->inspect($current->storage_disk, $current->storage_path, $current->sha256, $current->byte_size);
                    if ($verify() === EhiBlobVerifier::OK) {
                        $current->forceFill(['verified_at' => now()])->save();

                        return 0;
                    }
                    $this->publish($current->storage_disk, $current->storage_path, $staged);
                    if ($verify() !== EhiBlobVerifier::OK) {
                        throw new EhiIntakeFailed(EhiIntakeFailed::STORED_OBJECT_MISMATCH, 'a repaired member did not read back as written');
                    }
                    $current->forceFill(['verified_at' => now()])->save();

                    return 1;
                });
            } finally {
                fclose($staged);
            }
        }

        if ($intact !== []) {
            $this->writeGuard->run((int) $package->patient_id, function (PhrPatient $patient) use ($intact, $actorUserId): void {
                $this->access->ensureCanWrite($patient, $actorUserId);
                foreach (array_chunk($intact, self::INSERT_CHUNK) as $chunk) {
                    PhrEhiBlob::query()->where('patient_id', $patient->id)->whereIn('id', $chunk)->update(['verified_at' => now()]);
                }
            });
        }

        return $repaired;
    }

    /**
     * Re-read a member into a private temp file, hashing as it goes. Bytes that
     * differ from the first pass mean the source changed underneath the intake.
     *
     * @param  MemberPlan  $member
     * @return resource
     */
    private function stage(EhiPackageSource $source, array $member, EhiLimits $limits)
    {
        $temp = tmpfile();
        if (! is_resource($temp)) {
            throw new EhiIntakeFailed(EhiIntakeFailed::STORAGE_FAILED, 'a staging file could not be created');
        }
        try {
            $stream = $source->open($member['member']->entry);
            try {
                $copied = $this->archiveGuard->copyBounded($stream, $temp, $member['member']->entry->declaredBytes, $limits->maxMemberBytes);
            } finally {
                fclose($stream);
            }
        } catch (EhiPackageRejected $rejected) {
            fclose($temp);
            throw new EhiIntakeFailed(EhiIntakeFailed::SOURCE_CHANGED, 'a member could not be re-read as first measured', $rejected);
        } catch (Throwable $failure) {
            fclose($temp);
            throw $failure;
        }

        if ($copied['bytes'] !== $member['bytes'] || ! hash_equals($member['sha256'], $copied['sha256'])) {
            fclose($temp);
            throw new EhiIntakeFailed(EhiIntakeFailed::SOURCE_CHANGED, 'a member differs from the bytes first measured');
        }
        rewind($temp);

        return $temp;
    }

    /** @param resource|null $staged */
    private function publish(string $disk, string $path, $staged): void
    {
        if (! is_resource($staged)) {
            throw new LogicException('EHI member must be staged before it is published.');
        }
        rewind($staged);
        if (Storage::disk($disk)->put($path, $staged) === false) {
            throw new EhiIntakeFailed(EhiIntakeFailed::STORAGE_FAILED, 'a member could not be stored');
        }
    }

    /**
     * Remove an object this intake wrote, under the patient lock and only while no
     * blob row references it, so pre-existing evidence is never deleted.
     */
    private function compensate(int $patientId, string $sha256, string $disk, string $key): void
    {
        try {
            $this->writeGuard->run($patientId, function () use ($patientId, $sha256, $disk, $key): void {
                if (! PhrEhiBlob::query()->where('patient_id', $patientId)->where('sha256', $sha256)->exists()) {
                    Storage::disk($disk)->delete($key);
                }
            });
        } catch (ModelNotFoundException) {
            // The patient is gone, so nothing can reference a key under its prefix.
            try {
                Storage::disk($disk)->delete($key);
            } catch (Throwable) {
                // Preserve the original failure.
            }
        } catch (Throwable) {
            // Preserve the original failure.
        }
    }

    /**
     * Write every account and finding in one transaction. Accounts are upserted and
     * findings replaced, so a retried accounting converges on the same rows.
     *
     * @param  Plan  $plan
     */
    private function account(PhrEhiPackage $package, array $plan, EhiDialect $dialect, string $token, int $actorUserId): void
    {
        $this->underLease($package, $token, $actorUserId, function (PhrPatient $patient, PhrEhiPackage $current) use ($plan, $dialect): void {
            $now = now();
            $memberIds = PhrEhiPackageMember::query()->where('package_id', $current->id)->pluck('id', 'path_sha256');
            $memberId = static function (string $path) use ($memberIds): int {
                $id = $memberIds->get(hash('sha256', $path));
                if ($id === null) {
                    throw new LogicException('EHI accounting refers to a member that was not preserved.');
                }

                return (int) $id;
            };
            $dictionary = $plan['dictionary'];
            $fingerprint = $dictionary->isEmpty() ? null : $dictionary->fingerprint();
            $json = static fn (?array $value): ?string => $value === null ? null : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $accounts = [];
            foreach ($plan['accounts'] as $account) {
                $valid = $account['rows'] - $account['malformed'];
                $accounts[] = [
                    'patient_id' => $patient->id,
                    'package_id' => $current->id,
                    'member_id' => $memberId($account['path']),
                    'table_name' => $account['table'],
                    'state' => $account['state'],
                    'header_columns_json' => $json($account['header']),
                    'unknown_columns_json' => $json($account['unknown']),
                    'missing_columns_json' => $json($account['missing']),
                    'source_row_count' => $account['rows'],
                    'malformed_row_count' => $account['malformed'],
                    'disposition_counts_json' => $json([$account['disposition'] => $valid]),
                    'parser_version' => $dialect->version,
                    'dictionary_fingerprint' => $fingerprint,
                ];
            }
            foreach ($plan['missing'] as $table) {
                $accounts[] = [
                    'patient_id' => $patient->id,
                    'package_id' => $current->id,
                    'member_id' => null,
                    'table_name' => $table,
                    'state' => PhrEhiTableAccount::STATE_MISSING,
                    'header_columns_json' => null,
                    'unknown_columns_json' => null,
                    'missing_columns_json' => null,
                    'source_row_count' => 0,
                    'malformed_row_count' => 0,
                    'disposition_counts_json' => $json([]),
                    'parser_version' => $dialect->version,
                    'dictionary_fingerprint' => $fingerprint,
                ];
            }

            $columns = array_values(array_diff(array_keys($accounts[0] ?? []), ['package_id', 'table_name', 'patient_id']));
            foreach (array_chunk($accounts, self::INSERT_CHUNK) as $chunk) {
                PhrEhiTableAccount::query()->upsert($chunk, ['package_id', 'table_name'], $columns);
            }
            PhrEhiTableAccount::query()->where('package_id', $current->id)
                ->whereNotIn('table_name', array_column($accounts, 'table_name'))
                ->delete();

            $findings = [];
            $row = static fn (?int $member, array $finding): array => [
                'patient_id' => $patient->id,
                'package_id' => $current->id,
                'member_id' => $member,
                'code' => $finding['code'],
                'severity' => EhiFindingCode::SEVERITY[$finding['code']],
                'line_number' => $finding['line'],
                'byte_offset' => $finding['offset'],
                'column_name' => $finding['column'],
                'detail_json' => $json($finding['detail']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            foreach ($plan['package_findings'] as $finding) {
                $findings[] = $row(null, $finding);
            }
            foreach ($plan['findings'] as $path => $list) {
                $id = $memberId((string) $path);
                foreach ($list['list'] as $finding) {
                    $findings[] = $row($id, $finding);
                }
                if ($list['omitted'] > 0) {
                    $findings[] = $row($id, $this->finding(EhiFindingCode::FINDINGS_TRUNCATED, detail: ['omitted' => $list['omitted']]));
                }
            }
            PhrEhiFinding::query()->where('package_id', $current->id)->delete();
            foreach (array_chunk($findings, self::INSERT_CHUNK) as $chunk) {
                PhrEhiFinding::query()->insert($chunk);
            }

            $current->forceFill([
                'accounting_status' => PhrEhiPackage::ACCOUNTING_ACCOUNTED,
                // Unchanged on a first intake; refreshed when rules changed.
                'dictionary_fingerprint' => $fingerprint,
                'dictionary_version' => $plan['has_dictionary'] ? $dictionary->readerVersion : null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'failure_category' => null,
                'checkpoint_json' => [
                    ...($current->checkpoint_json ?? []),
                    'phase' => PhrEhiPackage::ACCOUNTING_ACCOUNTED,
                    'versions' => $this->versions($dialect),
                ],
            ]);
        });
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
                throw new EhiIntakeFailed(EhiIntakeFailed::LEASE_LOST, 'another intake of this package holds its lease');
            }
            $current->lease_expires_at = $this->leaseExpiry(now());
            $result = $step($patient, $current);
            $current->save();

            return $result;
        });
    }

    /**
     * Best effort: mark the package failed and release the lease so deletion is
     * not blocked by a dead intake. A lost lease belongs to someone else.
     */
    private function recordFailure(PhrEhiPackage $package, string $token, Throwable $failure): void
    {
        if ($failure instanceof ModelNotFoundException
            || ($failure instanceof EhiIntakeFailed && $failure->reason === EhiIntakeFailed::LEASE_LOST)) {
            return;
        }
        $category = match (true) {
            $failure instanceof EhiIntakeFailed => $failure->reason,
            $failure instanceof EhiPackageRejected => $failure->reason,
            $failure instanceof AuthorizationException => self::FAILURE_ACCESS_REVOKED,
            default => self::FAILURE_UNEXPECTED,
        };

        try {
            $this->writeGuard->run((int) $package->patient_id, function () use ($package, $token, $category): void {
                $current = PhrEhiPackage::query()->whereKey($package->id)->lockForUpdate()->first();
                if ($current === null || $current->lease_token !== $token) {
                    return;
                }
                $preserved = $current->preservation_status === PhrEhiPackage::PRESERVATION_PRESERVED;
                $current->forceFill([
                    'preservation_status' => $preserved ? PhrEhiPackage::PRESERVATION_PRESERVED : PhrEhiPackage::PRESERVATION_FAILED,
                    'accounting_status' => $preserved ? PhrEhiPackage::ACCOUNTING_FAILED : $current->accounting_status,
                    'failure_category' => $category,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                ])->save();
            });
        } catch (Throwable) {
            // Preserve the original failure; an unreleased lease simply expires.
        }
    }

    /** @return array{sha256: string, bytes: int, prefix: string} */
    private function hashMember(EhiPackageSource $source, EhiValidatedMember $member, EhiLimits $limits): array
    {
        $sink = @fopen(DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'wb');
        if (! is_resource($sink)) {
            throw new LogicException('EHI intake could not open a null sink.');
        }
        try {
            $stream = $source->open($member->entry);
            try {
                $prefix = EhiPrefixCapture::attach($stream, self::FIRST_LINE_BYTES);

                return [...$this->archiveGuard->copyBounded($stream, $sink, $member->entry->declaredBytes, $limits->maxMemberBytes), 'prefix' => (string) $prefix->prefix];
            } finally {
                fclose($stream);
            }
        } finally {
            fclose($sink);
        }
    }

    /** @param MemberPlan $member */
    private function readDocument(EhiPackageSource $source, array $member): string
    {
        $stream = $source->open($member['member']->entry);
        try {
            $html = stream_get_contents($stream, $member['bytes'] + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($html) || strlen($html) !== $member['bytes'] || ! hash_equals($member['sha256'], hash('sha256', $html))) {
            throw new EhiPackageRejected(EhiPackageRejected::SIZE_MISMATCH, 'EHI package refused (size_mismatch): a member changed while it was being read.');
        }

        return $html;
    }

    /** The first line of a byte prefix, exactly as firstLine() reads it from a stream. */
    private function lineOf(string $prefix): string
    {
        $prefix = substr($prefix, 0, self::FIRST_LINE_BYTES - 1);
        $newline = strpos($prefix, "\n");

        return rtrim($newline === false ? $prefix : substr($prefix, 0, $newline + 1), "\r\n");
    }

    private function firstLine(EhiPackageSource $source, EhiValidatedMember $member): string
    {
        $stream = $source->open($member->entry);
        try {
            $line = fgets($stream, self::FIRST_LINE_BYTES);
        } finally {
            fclose($stream);
        }

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    /**
     * @param  array<string, FindingList>  $findings
     * @param  array<string, int|string|bool|null>|null  $detail
     */
    private function addFinding(array &$findings, string $path, EhiLimits $limits, string $code, ?int $line = null, ?int $offset = null, ?string $column = null, ?array $detail = null): void
    {
        $findings[$path] ??= ['list' => [], 'omitted' => 0];
        if (count($findings[$path]['list']) >= $limits->maxFindingsPerMember) {
            $findings[$path]['omitted']++;

            return;
        }
        $findings[$path]['list'][] = $this->finding($code, $line, $offset, $column, $detail);
    }

    /**
     * @param  array<string, int|string|bool|null>|null  $detail
     * @return Finding
     */
    private function finding(string $code, ?int $line = null, ?int $offset = null, ?string $column = null, ?array $detail = null): array
    {
        return ['code' => $code, 'line' => $line, 'offset' => $offset, 'column' => $column, 'detail' => $detail];
    }

    /**
     * Key order is not significant: a JSON column may not keep it.
     *
     * @param  array<string, string>  $current
     */
    private function sameVersions(mixed $recorded, array $current): bool
    {
        if (! is_array($recorded)) {
            return false;
        }
        ksort($recorded);
        ksort($current);

        return $recorded === $current;
    }

    /** @return array<string, string> */
    private function versions(EhiDialect $dialect): array
    {
        return [
            'dialect' => $dialect->version,
            'classifier' => EhiMemberClassifier::VERSION,
            'detector' => EhiPackageDetector::VERSION,
            'dictionary_reader' => EhiDictionaryReader::VERSION,
            'out_of_scope' => EhiOutOfScopeTables::VERSION,
        ];
    }

    private function leaseExpiry(Carbon $now): Carbon
    {
        return $now->copy()->addMinutes((int) config('phr.ehi_lease_minutes'));
    }
}
