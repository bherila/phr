<?php

namespace App\Services\PHR\Eob;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\DataTransferObjects\AgentApi\EobUpsertResult;
use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrPatient;
use App\Services\AgentApi\MedicationFillUpsertService;
use App\Services\PHR\Fills\MedicationFillExternalId;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Writes an EOB claim and its lines for an agent: idempotent on the claim
 * fingerprint the console importers use, pending review, and, for a pharmacy
 * claim, one fill per line that names one, linked to the line that adjudicated it.
 */
final readonly class EobWriteService
{
    public const string PARSER_VERSION = 'agent-api-v1';

    public function __construct(
        private AgentClinicalRecordVersion $versions,
        private MedicationFillUpsertService $fills,
    ) {}

    /**
     * @param  array{source_document_id: int, external_id?: string|null, expected_version?: string|null, data: array<string, mixed>}  $input
     */
    public function upsert(PhrPatient $patient, AgentApiClientIdentity $client, array $input): EobUpsertResult
    {
        $documentId = (int) $input['source_document_id'];
        PhrDocument::query()->where('patient_id', $patient->id)->findOrFail($documentId);

        $claim = EobClaim::normalize($input['data']);
        $fingerprint = EobClaim::fingerprint($claim);
        $source = $client->importSource();
        $externalId = isset($input['external_id']) && $input['external_id'] !== '' ? (string) $input['external_id'] : EobClaim::externalId($claim, $fingerprint);
        $expected = $input['expected_version'] ?? null;

        /** @var array{0: PhrEob, 1: string, 2: array{by: string, import_source: string}|null} $written */
        $written = DB::transaction(function () use ($patient, $claim, $fingerprint, $source, $externalId, $expected, $documentId): array {
            PhrPatient::query()->whereKey($patient->id)->lockForUpdate()->first();

            // This connection's own EOB first: a change to a field the fingerprint does not cover
            // (the plan name, the source document) is a correction, not a duplicate.
            $own = PhrEob::query()->where('patient_id', $patient->id)->where('import_source', $source)->where('external_id', $externalId)->first();
            if ($own instanceof PhrEob) {
                if ($this->withoutFills($claim) == EobClaim::normalize($this->stored($own)) && $own->source_document_id === $documentId) {
                    return [$own, ClinicalUpsertResult::UNCHANGED, ['by' => 'claim_fingerprint', 'import_source' => $own->import_source]];
                }
                if ($expected === null || ! hash_equals($this->versions->for($own), $expected)) {
                    throw new ConflictHttpException('An EOB with this external_id exists with different content. Read it (eobs.get) and send its current version as expected_version, or use eobs.update.');
                }
                if ($fingerprint !== $own->claim_fingerprint
                    && PhrEob::query()->where('patient_id', $patient->id)->where('claim_fingerprint', $fingerprint)->whereKeyNot($own->id)->exists()) {
                    throw new ConflictHttpException('Another EOB already holds exactly this claim.');
                }
                $this->apply($own, $claim, $fingerprint, $documentId);

                return [$own->refresh(), ClinicalUpsertResult::UPDATED, null];
            }

            $same = PhrEob::query()->where('patient_id', $patient->id)->where('claim_fingerprint', $fingerprint)->orderBy('id')->first();
            if ($same instanceof PhrEob) {
                return [$same, ClinicalUpsertResult::UNCHANGED, ['by' => 'claim_fingerprint', 'import_source' => $same->import_source]];
            }

            // The same claim another source read slightly differently (an AI reading of a
            // PDF a parser also reads): never a second copy of one claim.
            $number = $claim['header']['claim_number'];
            if ($number !== null) {
                $sameClaim = PhrEob::query()
                    ->where('patient_id', $patient->id)
                    ->where('claim_number', $number)
                    ->where('claim_type', $claim['header']['claim_type'])
                    ->when(
                        $claim['header']['processed_date'] === null,
                        fn ($query) => $query->whereNull('processed_date'),
                        fn ($query) => $query->whereDate('processed_date', $claim['header']['processed_date']),
                    )
                    ->orderBy('id')->first();
                if ($sameClaim instanceof PhrEob) {
                    return [$sameClaim, ClinicalUpsertResult::UNCHANGED, ['by' => 'claim_number', 'import_source' => $sameClaim->import_source]];
                }
            }

            try {
                $eob = PhrEob::query()->create([
                    'patient_id' => $patient->id,
                    'user_id' => $patient->owner_user_id,
                    'import_source' => $source,
                    'external_id' => $externalId,
                    'review_status' => PhrReviewStatus::PENDING,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ConflictHttpException('The EOB was written at the same moment by another request; retry.');
            }
            $this->apply($eob, $claim, $fingerprint, $documentId);

            return [$eob->refresh(), ClinicalUpsertResult::CREATED, null];
        }, 3);

        [$eob, $outcome, $match] = $written;
        $lines = $eob->lines()->orderBy('line_number')->get();

        return new EobUpsertResult(
            $eob,
            $outcome,
            $this->versions->for($eob),
            $match,
            $lines,
            $this->writeFills($patient, $client, $eob, $lines, $claim['lines'], $documentId),
        );
    }

    /**
     * A partial correction of a claim this connection wrote. `data` carries only
     * the header fields to change; `lines`, when present, replaces every line.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PhrPatient $patient, AgentApiClientIdentity $client, int $eobId, string $expectedVersion, array $data, ?int $sourceDocumentId): EobUpsertResult
    {
        if ($sourceDocumentId !== null) {
            PhrDocument::query()->where('patient_id', $patient->id)->findOrFail($sourceDocumentId);
        }

        /** @var array{0: PhrEob, 1: string} $written */
        $written = DB::transaction(function () use ($patient, $client, $eobId, $expectedVersion, $data, $sourceDocumentId): array {
            PhrPatient::query()->whereKey($patient->id)->lockForUpdate()->first();
            $eob = PhrEob::query()->where('patient_id', $patient->id)->lockForUpdate()->findOrFail($eobId);
            if ($eob->import_source !== $client->importSource()) {
                throw new ConflictHttpException('This EOB was imported by another source; only the connection that wrote an EOB can correct it.');
            }
            if (! hash_equals($this->versions->for($eob), $expectedVersion)) {
                throw new ConflictHttpException('The EOB changed; fetch it and retry with its current version.');
            }

            $replacesLines = array_key_exists('lines', $data);
            $stored = $this->stored($eob);
            $current = array_merge($stored, $data);
            if ($replacesLines) {
                // Totals stated before the lines changed no longer describe them, unless re-stated.
                foreach (EobClaim::HEADER_MONEY as $key) {
                    if (! array_key_exists($key, $data) && $key !== 'check_amount') {
                        $current[$key] = null;
                    }
                }
            }
            $claim = EobClaim::normalize($current);
            $fingerprint = EobClaim::fingerprint($claim);
            // The fingerprint covers what identifies a claim, not every header field (the plan
            // name, say), so "nothing changed" is a comparison of everything that is stored.
            if ($this->withoutFills($claim) == $this->withoutFills(EobClaim::normalize($stored)) && ($sourceDocumentId === null || $sourceDocumentId === $eob->source_document_id)) {
                return [$eob, ClinicalUpsertResult::UNCHANGED];
            }
            $other = $fingerprint !== $eob->claim_fingerprint
                && PhrEob::query()->where('patient_id', $patient->id)->where('claim_fingerprint', $fingerprint)->whereKeyNot($eob->id)->exists();
            if ($other) {
                throw new ConflictHttpException('Another EOB already holds exactly this claim.');
            }
            $this->apply($eob, $claim, $fingerprint, $sourceDocumentId ?? $eob->source_document_id);

            return [$eob->refresh(), ClinicalUpsertResult::UPDATED];
        }, 3);

        return new EobUpsertResult($written[0], $written[1], $this->versions->for($written[0]), null, $written[0]->lines()->orderBy('line_number')->get(), []);
    }

    /**
     * @param  array{header: array<string, mixed>, lines: list<array<string, mixed>>}  $claim
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    private function withoutFills(array $claim): array
    {
        $lines = array_map(static fn (array $line): array => array_diff_key($line, ['fill' => true]), $claim['lines']);
        // The order lines were sent in is not part of the claim: they are compared by number.
        usort($lines, static fn (array $a, array $b): int => $a['line_number'] <=> $b['line_number']);

        return ['header' => $claim['header'], 'lines' => $lines];
    }

    /**
     * The EOB as the claim input it would have been written from.
     *
     * @return array<string, mixed>
     */
    private function stored(PhrEob $eob): array
    {
        return array_merge($eob->only([...EobClaim::HEADER_TEXT, ...EobClaim::HEADER_DATES, ...EobClaim::HEADER_MONEY, 'claim_type']), [
            'lines' => $eob->lines()->orderBy('line_number')->get()->map(
                fn (PhrEobLine $line): array => $line->only(['line_number', 'procedure_code', 'revenue_code', 'code_type', 'description', 'service_start', 'service_end', 'notes_applied', ...EobClaim::LINE_MONEY]),
            )->all(),
        ]);
    }

    /**
     * @param  array{header: array<string, mixed>, lines: list<array<string, mixed>>}  $claim
     */
    private function apply(PhrEob $eob, array $claim, string $fingerprint, ?int $documentId): void
    {
        $eob->fill([
            ...$claim['header'],
            'source_document_id' => $documentId,
            'claim_fingerprint' => $fingerprint,
            'parsed_data' => ['written_by' => 'agent_api'],
            'parser_version' => self::PARSER_VERSION,
            'lines_digest' => hash('sha256', json_encode($this->withoutFills($claim)['lines'], JSON_THROW_ON_ERROR)),
            // A claim that changed is a new claim for a reviewer to look at.
            'review_status' => PhrReviewStatus::PENDING,
        ]);
        $eob->save();

        // Lines are matched by number and updated in place, so what points at a line
        // (the fills it adjudicated) keeps pointing at it when the claim is corrected.
        $existing = $eob->lines()->get()->keyBy('line_number');
        $kept = [];
        foreach ($claim['lines'] as $line) {
            $attributes = array_diff_key($line, ['fill' => true]);
            $row = $existing->get($line['line_number']);
            if ($row instanceof PhrEobLine) {
                $row->fill($attributes)->save();
            } else {
                $row = PhrEobLine::query()->create(['eob_id' => $eob->id, 'patient_id' => $eob->patient_id, ...$attributes]);
            }
            $kept[] = $row->id;
        }
        PhrEobLine::query()->where('eob_id', $eob->id)->whereNotIn('id', $kept)->delete();
    }

    /**
     * One fill per line that names one, through the fills write path, so the same
     * dispensing read from a label, a receipt and this EOB is one fill. A line
     * whose fill cannot be written is reported, not fatal: the claim is saved and a
     * repeat of the call is a no-op for it.
     *
     * @param  Collection<int, PhrEobLine>  $lines
     * @param  list<array<string, mixed>>  $claimLines
     * @return list<array<string, mixed>>
     */
    private function writeFills(PhrPatient $patient, AgentApiClientIdentity $client, PhrEob $eob, Collection $lines, array $claimLines, int $documentId): array
    {
        $results = [];
        $ordered = $lines->sortBy('line_number')->values();
        $sent = collect($claimLines)->sortBy('line_number')->values();
        // A claim another source holds may number its lines differently. Lines correspond by
        // number when the procedure codes agree, else by position when every code agrees.
        $positional = $ordered->count() === $sent->count()
            && $sent->every(fn (array $sentLine, int $index): bool => strtoupper((string) $ordered[$index]->procedure_code) === strtoupper((string) $sentLine['procedure_code']));
        $byNumber = $lines->keyBy('line_number');
        foreach ($sent as $index => $claimLine) {
            if (! isset($claimLine['fill'])) {
                continue;
            }
            $candidate = $byNumber->get($claimLine['line_number']);
            $line = $candidate instanceof PhrEobLine && strtoupper((string) $candidate->procedure_code) === strtoupper((string) $claimLine['procedure_code'])
                ? $candidate
                : ($positional ? $ordered[$index] : null);
            if (! $line instanceof PhrEobLine) {
                $results[] = [
                    'line_id' => null, 'line_number' => (int) $claimLine['line_number'], 'fill_id' => null, 'outcome' => 'error',
                    'errors' => ['line' => ['This line does not correspond to a line of the claim PHR holds, so its fill was not linked; link it with medication_fills.upsert and eob_line_id.']],
                ];

                continue;
            }
            $fill = (array) $claimLine['fill'];
            $locator = mb_substr('claim '.($eob->claim_number ?? $eob->id).' line '.$line->line_number, 0, 255);
            $data = array_filter([
                ...$fill,
                'filled_on' => $fill['filled_on'] ?? $claimLine['service_start'],
                'pharmacy_name' => $fill['pharmacy_name'] ?? $eob->provider_name,
                'drug_as_printed' => $fill['drug_as_printed'] ?? $claimLine['description'],
                'patient_name_as_printed' => $fill['patient_name_as_printed'] ?? $eob->patient_name,
                'source_kind' => 'eob',
                'source_locator' => $fill['source_locator'] ?? $locator,
                'eob_line_id' => $line->id,
            ], static fn (mixed $value): bool => $value !== null);

            $entry = ['line_id' => (int) $line->id, 'line_number' => (int) $line->line_number];
            if (! isset($data['filled_on'])) {
                $results[] = [...$entry, 'fill_id' => null, 'outcome' => 'error', 'errors' => ['filled_on' => ['The fill has no date: send fill.filled_on, or give the line a service_start.']]];

                continue;
            }
            try {
                $result = $this->fills->upsert($patient, $client, ClinicalUpsertData::fromValidated('medication-fills', [
                    'external_id' => MedicationFillExternalId::derive($data, $documentId),
                    'source_document_id' => $documentId,
                    'expected_version' => null,
                    'data' => $data,
                ]));
                $results[] = [
                    ...$entry,
                    'fill_id' => (int) $result->record->id,
                    'outcome' => $result->outcome,
                    'dedupe' => $result->dedupe,
                ];
            } catch (ValidationException $exception) {
                $results[] = [...$entry, 'fill_id' => null, 'outcome' => 'error', 'errors' => $exception->errors()];
            } catch (ConflictHttpException $exception) {
                $results[] = [...$entry, 'fill_id' => null, 'outcome' => 'error', 'errors' => ['fill' => [$exception->getMessage()]]];
            }
        }

        return $results;
    }
}
