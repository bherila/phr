<?php

namespace App\Services\PHR\NativeBackup;

use App\Services\PHR\Eob\EobCorrectionHistory;
use Generator;
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

    /** Reserve the largest possible local-resolution representation before any conversion writes.
     * UUID values have fixed encoded width; these placeholders are used only for byte accounting.
     *
     * @param  array<string, mixed>  $record
     */
    public static function admissionBytes(array $record): int
    {
        $maximum = strlen(PhrNativeRecordCodec::canonicalJson($record)."\n");
        $bridge = json_decode($record['attributes']['legacy_provenance'], true, flags: JSON_THROW_ON_ERROR);
        $placeholder = '00000000-0000-4000-8000-000000000000';
        for ($mask = 1; $mask < 8; $mask++) {
            $attributes = $record['attributes'];
            $relationships = $record['relationships'];
            $variant = $bridge;
            if ($mask & 1) {
                $attributes['actor_provenance'] = json_encode(['kind' => 'native_actor', 'native_id' => $placeholder], JSON_THROW_ON_ERROR);
                $variant['resolved']['actor_native_id'] = $placeholder;
            }
            foreach (['before' => 2, 'after' => 4] as $side => $bit) {
                if (($mask & $bit) && $bridge['source'][$side.'_document'] !== null) {
                    $attributes[$side.'_document_provenance'] = null;
                    $relationships[$side.'_document_id'] = ['kind' => 'record', 'table' => 'phr_documents', 'nativeId' => $placeholder];
                    $variant['resolved'][$side.'_document_native_id'] = $placeholder;
                }
            }
            $attributes['legacy_provenance'] = json_encode($variant, JSON_THROW_ON_ERROR);
            $resolved = (new PhrNativeRecordCodec)->record($record['nativeId'], $attributes, $relationships);
            $maximum = max($maximum, strlen(PhrNativeRecordCodec::canonicalJson($resolved)."\n"));
        }

        return $maximum;
    }
}
