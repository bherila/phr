<?php

namespace App\Services\PHR\NativeBackup;

use App\Services\PHR\Eob\EobCorrectionHistory;
use Generator;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Lift old v1 JSON after physical hashes have been verified. No source integer is a destination identity. */
final class PhrNativeEobCorrectionCompatibility
{
    public const array TABLES = ['phr_eob_corrections', 'phr_eob_correction_lines'];

    /** @param array<string, mixed> $record
     * @return list<array<string, mixed>>
     */
    public static function legacy(array $record): array
    {
        $json = $record['attributes']['parsed_data'] ?? null;
        if (! is_string($json)) {
            return [];
        }
        $parsed = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($parsed) || ! array_key_exists('corrections', $parsed)) {
            return [];
        }
        $entries = $parsed['corrections'];
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new NativeRestoreException('invalid_archive');
        }
        foreach ($entries as $entry) {
            try {
                EobCorrectionHistory::assertLegacy($entry);
            } catch (LogicException) {
                throw new NativeRestoreException('invalid_archive');
            }
        }

        return $entries;
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function normalize(array $record): array
    {
        $legacy = self::legacy($record);
        $json = $record['attributes']['parsed_data'] ?? null;
        if (! is_string($json)) {
            return $record;
        }
        $parsed = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($parsed) || ! array_key_exists('corrections', $parsed)) {
            return $record;
        }
        unset($parsed['corrections']);
        $attributes = $record['attributes'];
        $attributes['parsed_data'] = json_encode($parsed, JSON_THROW_ON_ERROR);
        $attributes['correction_revision'] = max((int) ($attributes['correction_revision'] ?? 0), count($legacy));

        return (new PhrNativeRecordCodec)->record($record['nativeId'], $attributes, $record['relationships']);
    }

    /** @param array<string, mixed> $eob
     * @return Generator<int, array<string, mixed>>
     */
    public static function records(string $table, array $eob, string $patientNativeId): Generator
    {
        $codec = new PhrNativeRecordCodec;
        $patient = ['kind' => 'record', 'table' => 'phr_patients', 'nativeId' => $patientNativeId];
        foreach (self::legacy($eob) as $position => $entry) {
            $correctionId = EobCorrectionHistory::legacyId($eob['nativeId'], $position + 1);
            if ($table === 'phr_eob_corrections') {
                $attributes = ['ordinal' => $position + 1, 'corrected_at' => $entry['corrected_at'], 'previous_version' => $entry['previous_version'],
                    'actor_provenance' => json_encode(EobCorrectionHistory::unresolved($patientNativeId, 'actor', $entry['actor_user_id']), JSON_THROW_ON_ERROR),
                    'client_provenance' => json_encode(EobCorrectionHistory::client($patientNativeId, $entry['oauth_client_id']), JSON_THROW_ON_ERROR),
                    'legacy_provenance' => json_encode(EobCorrectionHistory::legacyProvenance($patientNativeId, $entry), JSON_THROW_ON_ERROR)];
                foreach (['before', 'after'] as $side) {
                    $snapshot = $entry[$side];
                    $documentId = $snapshot['source_document_id'] ?? null;
                    unset($snapshot['lines'], $snapshot['source_document_id']);
                    $attributes[$side.'_data'] = json_encode($snapshot, JSON_THROW_ON_ERROR);
                    $attributes[$side.'_document_provenance'] = $documentId === null ? null
                        : json_encode(EobCorrectionHistory::unresolved($patientNativeId, 'document', $documentId), JSON_THROW_ON_ERROR);
                }
                yield $codec->record($correctionId, $attributes, ['patient_id' => $patient,
                    'eob_id' => ['kind' => 'record', 'table' => 'phr_eobs', 'nativeId' => $eob['nativeId']],
                    'before_document_id' => null, 'after_document_id' => null]);
            } else {
                foreach (['before', 'after'] as $side) {
                    foreach ($entry[$side]['lines'] as $linePosition => $line) {
                        yield $codec->record(EobCorrectionHistory::legacyLineId($correctionId, $side, $linePosition),
                            ['side' => $side, 'position' => $linePosition, 'payload' => json_encode($line, JSON_THROW_ON_ERROR)],
                            ['patient_id' => $patient, 'correction_id' => ['kind' => 'record', 'table' => 'phr_eob_corrections', 'nativeId' => $correctionId]]);
                    }
                }
            }
        }
    }

    /** Only used when comparing an archive predating the history generation.
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function legacyView(array $record): array
    {
        $attributes = $record['attributes'];
        $bridge = isset($attributes['legacy_provenance']) ? json_decode($attributes['legacy_provenance'], true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($bridge) || ! is_array($bridge['source'] ?? null) || ! is_array($bridge['resolved'] ?? null)) {
            return $record;
        }
        $source = $bridge['source'];
        $actor = json_decode($attributes['actor_provenance'], true, flags: JSON_THROW_ON_ERROR);
        $resolvedActor = $bridge['resolved']['actor_native_id'] ?? null;
        $expectedActor = $resolvedActor === null ? $source['actor'] : ['kind' => 'native_actor', 'native_id' => $resolvedActor];
        if ($actor !== $expectedActor) {
            throw new NativeRestoreException('record_conflict');
        }
        $attributes['actor_provenance'] = json_encode($source['actor'], JSON_THROW_ON_ERROR);
        $relationships = $record['relationships'];
        foreach (['before', 'after'] as $side) {
            $expectedId = $bridge['resolved'][$side.'_document_native_id'] ?? null;
            $actualId = $relationships[$side.'_document_id']['nativeId'] ?? null;
            $actualProvenance = isset($attributes[$side.'_document_provenance'])
                ? json_decode($attributes[$side.'_document_provenance'], true, flags: JSON_THROW_ON_ERROR) : null;
            $expectedProvenance = $expectedId === null ? $source[$side.'_document'] : null;
            if ($actualId !== $expectedId || $actualProvenance !== $expectedProvenance) {
                throw new NativeRestoreException('record_conflict');
            }
            $relationships[$side.'_document_id'] = null;
            $attributes[$side.'_document_provenance'] = $source[$side.'_document'] === null ? null
                : json_encode($source[$side.'_document'], JSON_THROW_ON_ERROR);
        }
        $bridge['resolved'] = [];
        $attributes['legacy_provenance'] = json_encode($bridge, JSON_THROW_ON_ERROR);

        return (new PhrNativeRecordCodec)->record($record['nativeId'], $attributes, $relationships);
    }

    /** @param list<array<string, mixed>> $entries */
    public static function historyHash(array $entries): string
    {
        return hash('sha256', PhrNativeRecordCodec::canonicalJson(['history' => $entries]));
    }

    /** Exact width of the chosen local-resolution representation. UUID placeholders
     * are used only for accounting when a known local actor has no assigned UUID yet.
     *
     * @param  array<string, mixed>  $record
     */
    public static function resolvedBytes(array $record, int $mask): int
    {
        $attributes = $record['attributes'];
        $relationships = $record['relationships'];
        $bridge = json_decode($attributes['legacy_provenance'], true, flags: JSON_THROW_ON_ERROR);
        $placeholder = '00000000-0000-4000-8000-000000000000';
        if ($mask & 1) {
            $attributes['actor_provenance'] = json_encode(['kind' => 'native_actor', 'native_id' => $placeholder], JSON_THROW_ON_ERROR);
            $bridge['resolved']['actor_native_id'] = $placeholder;
        }
        foreach (['before' => 2, 'after' => 4] as $side => $bit) {
            if ($mask & $bit) {
                $attributes[$side.'_document_provenance'] = null;
                $relationships[$side.'_document_id'] = ['kind' => 'record', 'table' => 'phr_documents', 'nativeId' => $placeholder];
                $bridge['resolved'][$side.'_document_native_id'] = $placeholder;
            }
        }
        $attributes['legacy_provenance'] = json_encode($bridge, JSON_THROW_ON_ERROR);
        $resolved = (new PhrNativeRecordCodec)->record($record['nativeId'], $attributes, $relationships);

        return strlen(PhrNativeRecordCodec::canonicalJson($resolved)."\n");
    }

    /** Charge the exact chosen conversion before any materialization. These choices
     * participate in the approval digest and are recomputed under the apply lock.
     *
     * @param  array<string, array{record_id: int, history_hash: string, resolutions?: array<string, array{mask: int, bytes: int}>}>  $migrations
     * @return array<string, array{record_id: int, history_hash: string, resolutions?: array<string, array{mask: int, bytes: int}>}>
     */
    public static function reserveConversions(PhrNativeRestoreArchive $archive, int $patientId, array $migrations, bool $locking): array
    {
        if ($migrations === []) {
            return [];
        }
        $predictions = [];
        foreach ($migrations as $eobNativeId => $proof) {
            $eob = DB::table('phr_eobs')->where('patient_id', $patientId)->where('id', $proof['record_id'])->first();
            if ($eob === null) {
                throw new NativeRestoreException('preview_changed');
            }
            $legacy = self::legacy(['attributes' => ['parsed_data' => $eob->parsed_data]]);
            if (! hash_equals($proof['history_hash'], self::historyHash($legacy))) {
                throw new NativeRestoreException('preview_changed');
            }
            $restored = DB::table('phr_native_record_identities')->where('patient_id', $patientId)
                ->where('record_table', 'phr_eobs')->where('record_id', $proof['record_id'])
                ->where(fn ($query) => $query->whereNotNull('restored_at')->orWhereNotNull('restore_attempt_id'))->exists();
            foreach ($legacy as $index => $entry) {
                $mask = 0;
                if (! $restored) {
                    $actor = DB::table('users')->where('id', $entry['actor_user_id'])->select('id');
                    if ($locking) {
                        $actor->lockForUpdate();
                    }
                    $mask = $actor->first() !== null ? 1 : 0;
                    foreach (['before' => 2, 'after' => 4] as $side => $bit) {
                        $document = DB::table('phr_documents')->where('patient_id', $patientId)
                            ->where('id', $entry[$side]['source_document_id'] ?? null)->select('id');
                        if ($locking) {
                            $document->lockForUpdate();
                        }
                        if ($document->first() !== null) {
                            $mask |= $bit;
                        }
                    }
                }
                $predictions[EobCorrectionHistory::legacyId($eobNativeId, $index + 1)] = ['eob' => $eobNativeId, 'mask' => $mask];
            }
        }
        $budget = new NativeResourceBudget(true);
        foreach (PhrNativeBackupCatalog::included() as $table => $definition) {
            foreach ($archive->records($table) as $record) {
                $prediction = $table === 'phr_eob_corrections' ? ($predictions[$record['nativeId']] ?? null) : null;
                $bytes = $prediction === null ? strlen(PhrNativeRecordCodec::canonicalJson($record)."\n") : self::resolvedBytes($record, $prediction['mask']);
                $budget->charge($bytes);
                if ($prediction !== null) {
                    $migrations[$prediction['eob']]['resolutions'][$record['nativeId']] = ['mask' => $prediction['mask'], 'bytes' => $bytes];
                }
            }
        }

        return $migrations;
    }
}
