<?php

namespace App\Services\PHR\Import;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Services\AgentApi\MedicationFillUpsertService;
use App\Services\PHR\Fills\MedicationFillExternalId;
use App\Services\PHR\Fills\PatientNameMatcher;
use App\Support\PHR\Validation\MedicationFillDataRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Writes the prescription fills an AI extraction read from a document, through the
 * same server path as medication_fills.upsert: the server resolves the NDC, links
 * or creates the medication, and the fill waits for review. The fill always stays
 * on the document's patient; a printed name that does not match is flagged (the
 * review queue compares the name stored on the fill) and warned about here.
 */
final readonly class PhrDocumentFillImporter
{
    public const string IMPORT_SOURCE = 'genai';

    public function __construct(
        private MedicationFillUpsertService $upserts,
        private PatientNameMatcher $names,
    ) {}

    /**
     * @param  list<mixed>  $fills
     */
    public function import(PhrPatient $patient, PhrDocument $document, array $fills): PhrDocumentFillImport
    {
        $result = new PhrImportResult;
        $lines = [];
        foreach ($fills as $index => $fill) {
            if (! is_array($fill)) {
                $result->addSkipped();

                continue;
            }
            $data = $this->data($fill, $document, (int) $index);
            if ($data === null) {
                $result->addSkipped();

                continue;
            }
            try {
                $validated = Validator::make($data, MedicationFillDataRules::rules())->validate();
                // Lines that share a locator (several on one page) and have no Rx number
                // must still get their own identity, so the record key joins the locator
                // for the derivation only.
                $key = $fill['record_key'] ?? null;
                $identity = $validated;
                if (is_string($key) && $key !== '') {
                    $identity['source_locator'] = ($validated['source_locator'] ?? '').'|'.$key;
                }
                $upsert = $this->upserts->upsertFromSource($patient, self::IMPORT_SOURCE, ClinicalUpsertData::fromValidated('medication-fills', [
                    'external_id' => MedicationFillExternalId::derive($identity, $document->id),
                    'source_document_id' => $document->id,
                    'expected_version' => null,
                    'data' => $validated,
                ]));
            } catch (ValidationException) {
                $result->addSkipped();
                $result->warn('A prescription fill could not be imported: it needs a drug (NDC or printed name), a fill date and a quantity.');

                continue;
            } catch (ConflictHttpException) {
                // Someone changed or withdrew this fill since: the extraction does not overwrite them.
                $result->addSkipped();

                continue;
            }

            match ($upsert->outcome) {
                ClinicalUpsertResult::CREATED => $result->addCreated(),
                ClinicalUpsertResult::UPDATED => $result->addUpdated(),
                default => $result->addUnchanged(),
            };
            $line = $this->line($fill);
            $line['names'] = array_values(array_filter([
                $upsert->medication?->name,
                $upsert->record->brand_name,
                ...($upsert->record->substance_names ?? []),
            ]));
            $lines[] = $line;
            if ($this->names->mismatches($patient, $upsert->record->patient_name_as_printed)) {
                $result->warn("A prescription fill is printed for \"{$upsert->record->patient_name_as_printed}\", not {$patient->display_name}; it was kept on this patient and flagged for review.");
            }
        }

        return new PhrDocumentFillImport($result, $lines);
    }

    /**
     * Drops the medications that describe a line already written as a fill: the same
     * drug as printed, or the same NDC, on the same document.
     *
     * @param  array<array-key, mixed>  $medications
     * @param  list<array{printed: string, ndc: string, names: list<string>}>  $lines
     * @return array<array-key, mixed>
     */
    public function withoutFillLines(array $medications, array $lines): array
    {
        $kept = array_filter($medications, function (mixed $medication) use ($lines): bool {
            if (! is_array($medication)) {
                return true;
            }
            $name = self::words((string) ($medication['name'] ?? ''));
            $digits = self::digits(($medication['name'] ?? '').' '.($medication['raw_text'] ?? ''));
            foreach ($lines as $line) {
                if ($name !== '' && ($name === $line['printed'] || in_array($name, array_map(self::words(...), $line['names']), true))) {
                    return false;
                }
                if ($line['ndc'] !== '' && str_contains($digits, $line['ndc'])) {
                    return false;
                }
            }

            return true;
        });

        return array_is_list($medications) ? array_values($kept) : $kept;
    }

    /**
     * @param  array<string, mixed>  $fill
     * @return array{printed: string, ndc: string, names: list<string>}
     */
    private function line(array $fill): array
    {
        $ndc = self::digits((string) ($fill['ndc'] ?? ''));

        return [
            'printed' => self::words((string) ($fill['drug_as_printed'] ?? '')),
            'ndc' => strlen($ndc) >= 10 ? $ndc : '',
            'names' => [],
        ];
    }

    /**
     * The upsert's data object, from the printed values. Dollars become cents here.
     *
     * @param  array<string, mixed>  $fill
     * @return array<string, mixed>|null
     */
    private function data(array $fill, PhrDocument $document, int $index): ?array
    {
        // Carbon reads a blank string as now, which would invent a dispensing date.
        if (! is_string($fill['filled_on'] ?? null) || trim($fill['filled_on']) === '') {
            return null;
        }
        try {
            $filledOn = Carbon::parse($fill['filled_on'])->toDateString();
        } catch (Throwable) {
            return null;
        }
        if (! isset($fill['quantity']) || ! is_numeric($fill['quantity'])) {
            return null;
        }

        $data = ['filled_on' => $filledOn, 'quantity' => round((float) $fill['quantity'], 3), 'source_kind' => $this->sourceKind($document)];
        foreach (['ndc', 'drug_as_printed', 'patient_name_as_printed', 'quantity_unit', 'rx_number', 'directions', 'prescriber_name', 'pharmacy_name', 'pharmacy_location'] as $field) {
            $value = $fill[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $data[$field] = trim($value);
            }
        }
        foreach (['days_supply', 'refills_remaining'] as $field) {
            if (isset($fill[$field]) && is_numeric($fill[$field])) {
                $data[$field] = (int) $fill[$field];
            }
        }
        foreach (['paid_dollars' => 'paid_cents', 'retail_dollars' => 'retail_cents'] as $from => $to) {
            if (isset($fill[$from]) && is_numeric($fill[$from]) && (float) $fill[$from] >= 0) {
                $data[$to] = (int) round((float) $fill[$from] * 100);
            }
        }

        // Where in the document the line was read. It also separates two lines of one
        // document that have no Rx number, so each gets one even if the model gave none.
        $locator = $fill['source_locator'] ?? $fill['record_key'] ?? null;
        $data['source_locator'] = is_string($locator) && trim($locator) !== '' ? mb_substr(trim($locator), 0, 255) : 'line '.($index + 1);

        return $data;
    }

    private function sourceKind(PhrDocument $document): string
    {
        $mime = strtolower((string) $document->mime_type);
        $name = strtolower((string) $document->original_filename);

        return match (true) {
            str_starts_with($mime, 'image/') => 'label_photo',
            str_contains($mime, 'csv') || str_ends_with($name, '.csv') => 'csv',
            default => 'receipt_pdf',
        };
    }

    private static function words(string $value): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $value));
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }
}
