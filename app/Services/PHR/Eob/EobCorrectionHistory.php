<?php

namespace App\Services\PHR\Eob;

use App\Models\PhrEob;
use App\Models\PhrEobCorrection;
use App\Models\PhrEobCorrectionLine;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Services\PHR\NativeBackup\PhrNativeIdentityRepository;
use App\Services\PHR\NativeBackup\PhrNativeSnapshotService;
use App\Support\AgentApi\AgentApiClientIdentity;
use Illuminate\Support\Facades\DB;
use LogicException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Lossless ordered snapshots, with one row per historical line rather than one growing JSON record. */
final readonly class EobCorrectionHistory
{
    // PostgreSQL's INTEGER ceiling also bounds the unsigned columns on other engines.
    private const int MAX_REVISION = 2_147_483_647;

    public function __construct(private PhrNativeIdentityRepository $identities, private PhrPatientArtifactWriteGuard $guard) {}

    /** Normalize existing local JSON before native snapshot byte preflight, without advancing the clinical version. */
    public function normalizePatient(int $patientId): void
    {
        $this->guard->run($patientId, function () use ($patientId): void {
            // Take the patient lock before the first ordinary read. In a repeatable-
            // read backup this pins the snapshot only after any earlier EOB writer
            // has committed, so the migration and subsequent records agree.
            foreach (DB::table('phr_eobs')->where('patient_id', $patientId)->select('id')->orderBy('id')->lazyById(PhrNativeSnapshotService::chunkSize(), 'id') as $candidate) {
                // Hydrate just one legacy candidate before the native per-record
                // preflight: oversized old JSON is split into bounded rows here.
                // A deleted EOB (#444) keeps its history, so it is normalized too.
                $eob = PhrEob::withTrashed()->where('patient_id', $patientId)->lockForUpdate()->findOrFail($candidate->id);
                $this->normalize($eob);
            }
        });
    }

    /** Called only inside the patient's write transaction.
     * @return array<string, list<string>> identities produced by this conversion only
     */
    public function normalize(PhrEob $eob): array
    {
        $parsed = $eob->parsed_data;
        if (! is_array($parsed) || ! array_key_exists('corrections', $parsed)) {
            return [];
        }
        $this->requireTransaction();
        $legacy = $parsed['corrections'];
        if (! is_array($legacy) || ! array_is_list($legacy)) {
            throw new LogicException('Unrecognized EOB correction history must not be discarded.');
        }
        if (PhrEobCorrection::query()->where('eob_id', $eob->id)->exists()) {
            throw new LogicException('Mixed EOB correction history must be reconciled explicitly.');
        }
        $patientId = (int) $eob->patient_id;
        $patientNativeId = $this->nativeId($patientId, 'phr_patients', $patientId);
        $eobNativeId = $this->nativeId($patientId, 'phr_eobs', (int) $eob->id);
        // A pre-fix restore copied embedded integers verbatim. Even if such an
        // integer now names a valid local row, it cannot prove source identity.
        $restored = DB::table('phr_native_record_identities')->where('patient_id', $patientId)
            ->where('record_table', 'phr_eobs')->where('record_id', $eob->id)
            ->where(fn ($query) => $query->whereNotNull('restored_at')->orWhereNotNull('restore_attempt_id'))->exists();
        $produced = [];
        foreach ($legacy as $position => $entry) {
            self::assertLegacy($entry);
            $actorId = $entry['actor_user_id'];
            $actor = ! $restored && DB::table('users')->where('id', $actorId)->exists()
                ? ['kind' => 'native_actor', 'native_id' => $this->nativeId($patientId, 'users', $actorId)]
                : self::unresolved($patientNativeId, 'actor', $actorId);
            $inserted = $this->store($eob, $position + 1, $entry['before'], $entry['after'], $actor,
                self::client($patientNativeId, $entry['oauth_client_id']), $entry['corrected_at'], $entry['previous_version'],
                self::legacyId($eobNativeId, $position + 1), ! $restored, self::legacyProvenance($patientNativeId, $entry));
            foreach ($inserted as $table => $nativeIds) {
                foreach ($nativeIds as $nativeId) {
                    $produced[$table][] = $nativeId;
                }
            }
        }
        unset($parsed['corrections']);
        // This is a representation migration, not a new clinical correction.
        // Leave timestamps, review status and the clinical version unchanged.
        $eob->parsed_data = $parsed;
        $revision = max((int) $eob->getAttribute('correction_revision'), count($legacy));
        $eob->correction_revision = $revision;
        DB::table('phr_eobs')->where('id', $eob->id)->update(['parsed_data' => $eob->getAttributes()['parsed_data'], 'correction_revision' => $revision]);
        $eob->syncOriginalAttribute('parsed_data');
        $eob->syncOriginalAttribute('correction_revision');

        return $produced;
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    public function append(PhrEob $eob, array $before, array $after, int $actorUserId, AgentApiClientIdentity $client, string $previousVersion): void
    {
        $this->requireTransaction();
        $currentRevision = (int) $eob->getAttribute('correction_revision');
        if ($currentRevision >= self::MAX_REVISION) {
            throw new ConflictHttpException('The EOB correction revision is exhausted; the correction was not written.');
        }
        $patientId = (int) $eob->patient_id;
        $patientNativeId = $this->nativeId($patientId, 'phr_patients', $patientId);
        $ordinal = (int) PhrEobCorrection::query()->where('eob_id', $eob->id)->max('ordinal') + 1;
        if ($ordinal > self::MAX_REVISION) {
            throw new ConflictHttpException('The EOB correction history ordinal is exhausted; the correction was not written.');
        }
        $this->store($eob, $ordinal, $before, $after,
            ['kind' => 'native_actor', 'native_id' => $this->nativeId($patientId, 'users', $actorUserId)],
            self::client($patientNativeId, $client->id), now()->toIso8601String(), $previousVersion);
        $eob->correction_revision = max($currentRevision + 1, $ordinal);
        $eob->save();
    }

    /** Ordered before/after snapshots. Attribution is an opaque principal reference, never a destination user/client ID.
     * @return list<array<string, mixed>>
     */
    public function forEob(PhrEob $eob): array
    {
        $history = [];
        foreach (PhrEobCorrection::query()->where('patient_id', $eob->patient_id)->where('eob_id', $eob->id)->orderBy('ordinal')->get() as $correction) {
            $entry = [
                'actor' => $correction->actor_provenance, 'oauth_client' => $correction->client_provenance,
                'corrected_at' => $correction->corrected_at, 'previous_version' => $correction->previous_version,
            ];
            foreach (['before', 'after'] as $side) {
                $snapshot = $side === 'before' ? $correction->before_data : $correction->after_data;
                $entry[$side] = [...$snapshot, 'source_document_id' => $correction->{$side.'_document_id'},
                    'source_document_provenance' => $correction->{$side.'_document_provenance'},
                    'lines' => PhrEobCorrectionLine::query()->where('patient_id', $eob->patient_id)
                        ->where('correction_id', $correction->id)->where('side', $side)->orderBy('position')->pluck('payload')->all()];
            }
            $history[] = $entry;
        }

        return $history;
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $actor
     * @param  array<string, mixed>  $client
     * @param  array<string, mixed>|null  $legacyProvenance
     * @return array<string, list<string>>
     */
    private function store(PhrEob $eob, int $ordinal, array $before, array $after, array $actor, array $client, string $correctedAt, string $previousVersion, ?string $nativeId = null, bool $resolveDocuments = true, ?array $legacyProvenance = null): array
    {
        $patientId = (int) $eob->patient_id;
        $event = ['patient_id' => $patientId, 'eob_id' => $eob->id, 'ordinal' => $ordinal,
            'corrected_at' => $correctedAt, 'previous_version' => $previousVersion,
            'actor_provenance' => $actor, 'client_provenance' => $client];
        $lines = [];
        $produced = [];
        if ($legacyProvenance !== null && $actor['kind'] === 'native_actor') {
            $legacyProvenance['resolved']['actor_native_id'] = $actor['native_id'];
        }
        foreach (['before' => $before, 'after' => $after] as $side => $snapshot) {
            $lines[$side] = $snapshot['lines'];
            $documentId = $snapshot['source_document_id'] ?? null;
            unset($snapshot['lines'], $snapshot['source_document_id']);
            $known = $resolveDocuments && $documentId !== null && DB::table('phr_documents')->where('patient_id', $patientId)->where('id', $documentId)->exists();
            $event[$side.'_document_id'] = $known ? $documentId : null;
            $event[$side.'_document_provenance'] = $documentId !== null && ! $known
                ? self::unresolved($this->nativeId($patientId, 'phr_patients', $patientId), 'document', $documentId) : null;
            $event[$side.'_data'] = $snapshot;
            if ($known && $legacyProvenance !== null) {
                $legacyProvenance['resolved'][$side.'_document_native_id'] = $this->nativeId($patientId, 'phr_documents', $documentId);
            }
        }
        $event['legacy_provenance'] = $legacyProvenance;
        $correction = PhrEobCorrection::query()->create($event);
        if ($nativeId !== null) {
            $produced['phr_eob_corrections'][] = $nativeId;
            DB::table('phr_native_record_identities')->insert(['patient_id' => $patientId, 'record_table' => 'phr_eob_corrections',
                'record_id' => $correction->id, 'native_id' => $nativeId, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($lines as $side => $snapshots) {
            foreach (array_chunk($snapshots, 200, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $position => $payload) {
                    $rows[] = ['patient_id' => $patientId, 'correction_id' => $correction->id, 'side' => $side,
                        'position' => $position, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)];
                }
                DB::table('phr_eob_correction_lines')->insert($rows);
                if ($nativeId !== null) {
                    $identities = [];
                    foreach (DB::table('phr_eob_correction_lines')->where('correction_id', $correction->id)
                        ->where('side', $side)->whereIn('position', array_keys($chunk))->get(['id', 'position']) as $line) {
                        $identities[] = ['patient_id' => $patientId, 'record_table' => 'phr_eob_correction_lines', 'record_id' => $line->id,
                            'native_id' => self::legacyLineId($nativeId, $side, (int) $line->position), 'created_at' => now(), 'updated_at' => now()];
                        $produced['phr_eob_correction_lines'][] = self::legacyLineId($nativeId, $side, (int) $line->position);
                    }
                    DB::table('phr_native_record_identities')->insert($identities);
                }
            }
        }

        return $produced;
    }

    public static function assertLegacy(mixed $entry): void
    {
        if (! is_array($entry) || array_diff(array_keys($entry), ['actor_user_id', 'oauth_client_id', 'corrected_at', 'previous_version', 'before', 'after']) !== []
            || ! array_key_exists('actor_user_id', $entry) || ! array_key_exists('oauth_client_id', $entry)
            || ! is_int($entry['actor_user_id']) || ! is_string($entry['oauth_client_id'])
            || ! is_string($entry['corrected_at'] ?? null) || ! is_string($entry['previous_version'] ?? null)) {
            throw new LogicException('Unrecognized EOB correction history must not be discarded.');
        }
        foreach (['before', 'after'] as $side) {
            if (! is_array($entry[$side] ?? null) || ! is_array($entry[$side]['lines'] ?? null) || ! array_is_list($entry[$side]['lines'])) {
                throw new LogicException('Unrecognized EOB correction snapshot must not be discarded.');
            }
            foreach ($entry[$side]['lines'] as $line) {
                if (! is_array($line)) {
                    throw new LogicException('Unrecognized EOB correction line must not be discarded.');
                }
            }
        }
    }

    /** @return array{kind: string, source_patient_native_id: string, reference: string} */
    public static function unresolved(string $patientNativeId, string $kind, mixed $id): array
    {
        return ['kind' => 'unresolved_source_'.$kind, 'source_patient_native_id' => $patientNativeId,
            'reference' => Uuid::uuid5($patientNativeId, $kind.':'.json_encode($id, JSON_THROW_ON_ERROR))->toString()];
    }

    /** @return array<string, mixed> */
    public static function client(string $patientNativeId, mixed $id): array
    {
        return ['kind' => 'source_oauth_client', 'source_patient_native_id' => $patientNativeId, 'reference' => $id];
    }

    public static function legacyId(string $eobNativeId, int $ordinal): string
    {
        return Uuid::uuid5($eobNativeId, 'correction:'.$ordinal)->toString();
    }

    public static function legacyLineId(string $correctionNativeId, string $side, int $position): string
    {
        return Uuid::uuid5($correctionNativeId, $side.':'.$position)->toString();
    }

    /** Source-only aliases let old archives compare to a locally resolved conversion.
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function legacyProvenance(string $patientNativeId, array $entry): array
    {
        $source = ['actor' => self::unresolved($patientNativeId, 'actor', $entry['actor_user_id'])];
        foreach (['before', 'after'] as $side) {
            $id = $entry[$side]['source_document_id'] ?? null;
            $source[$side.'_document'] = $id === null ? null : self::unresolved($patientNativeId, 'document', $id);
        }

        return ['source' => $source, 'resolved' => []];
    }

    private function nativeId(int $patientId, string $table, int $recordId): string
    {
        // This service can be retried after a transaction rollback. Never keep
        // an identity assigned in a rolled-back attempt in a service cache.
        return $this->identities->forRecords($patientId, $table, [$recordId])[$recordId];
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('EOB correction history requires the patient write transaction.');
        }
    }
}
