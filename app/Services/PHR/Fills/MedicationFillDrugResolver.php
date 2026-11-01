<?php

namespace App\Services\PHR\Fills;

use App\Models\PhrMedicationFill;
use App\Services\PHR\Ndc\NdcLookupService;
use App\Services\PHR\Ndc\NdcNumber;
use App\Services\PHR\Ndc\NdcResolution;
use Illuminate\Validation\ValidationException;

/**
 * Turns the NDC a client sent into the drug columns of a fill. Clients never
 * supply drug fields: the server resolves them from openFDA (or RxNav's NDC history for a discontinued product), so a model that
 * misreads a label cannot invent a strength.
 */
class MedicationFillDrugResolver
{
    /** Columns that describe the drug and are only ever set here. */
    public const array DRUG_COLUMNS = [
        'ndc_as_captured', 'ndc_10', 'ndc_11', 'ndc_status', 'ndc_source', 'substance_names', 'strengths', 'brand_name',
        'dosage_form', 'route', 'labeler_name', 'package_description', 'rxcui', 'fda_snapshot', 'fda_fetched_at',
    ];

    public function __construct(private readonly NdcLookupService $ndc) {}

    /**
     * @param  array<string, mixed>  $input  the request's data object
     * @param  bool  $strict  a person is waiting: ambiguity and a miss are validation errors, not saved states
     * @return array{attributes: array<string, mixed>, resolution: ?NdcResolution}
     *
     * @throws ValidationException
     */
    public function resolve(array $input, ?PhrMedicationFill $existing, bool $strict): array
    {
        $touchesNdc = array_key_exists('ndc', $input) || array_key_exists('ndc_candidate', $input);
        if ($existing !== null && ! $touchesNdc) {
            // The drug is unchanged, but a fill whose drug the server could not resolve is
            // listed under what the label said, so an edit may not blank that out.
            if ($existing->ndc_status !== PhrMedicationFill::NDC_RESOLVED && $this->printed($input, $existing) === null) {
                throw ValidationException::withMessages([$strict ? 'drug_as_printed' : 'data.drug_as_printed' => 'This fill has no resolved drug, so it needs the drug as printed on the label.']);
            }

            return ['attributes' => [], 'resolution' => null];
        }

        $captured = trim((string) ($input['ndc'] ?? ($existing !== null && ! array_key_exists('ndc', $input) ? $existing->ndc_as_captured : '')));
        $candidate = isset($input['ndc_candidate']) && trim((string) $input['ndc_candidate']) !== '' ? trim((string) $input['ndc_candidate']) : null;
        $printed = $this->printed($input, $existing);

        if ($captured === '' && $candidate === null) {
            if ($printed === null) {
                throw ValidationException::withMessages(['ndc' => 'Enter an NDC, or describe the drug as printed on the label.']);
            }

            return ['attributes' => $this->cleared(PhrMedicationFill::NDC_MANUAL, null), 'resolution' => null];
        }

        // The same NDC again changes nothing, so a retried request neither calls
        // openFDA nor moves the fetch time that would make it look edited.
        if ($existing !== null
            && $existing->ndc_status === PhrMedicationFill::NDC_RESOLVED
            && $existing->ndc_as_captured === $captured
            && ($candidate === null || $candidate === $existing->ndc_10)) {
            return ['attributes' => [], 'resolution' => null];
        }

        if ($candidate !== null) {
            if ($captured !== '' && ! in_array($candidate, NdcNumber::packageCandidates($captured), true)) {
                throw ValidationException::withMessages(['ndc_candidate' => 'This package is not one of the readings of the NDC.']);
            }
            $resolution = $this->ndc->lookupChosen($candidate, $captured !== '' ? $captured : $candidate);
        } else {
            $resolution = $this->ndc->lookup($captured);
        }

        if ($resolution->status === NdcResolution::RESOLVED && ($chosen = $resolution->chosen()) !== null) {
            return [
                'attributes' => [
                    ...$chosen->fillAttributes(),
                    'ndc_as_captured' => $resolution->ndcAsCaptured,
                    'ndc_status' => PhrMedicationFill::NDC_RESOLVED,
                ],
                'resolution' => $resolution,
            ];
        }

        $allowUnresolved = (bool) ($input['allow_unresolved'] ?? false);
        if ($strict) {
            if ($resolution->status === NdcResolution::AMBIGUOUS) {
                throw ValidationException::withMessages(['ndc_candidate' => 'Choose one of the matching packages']);
            }
            if (! $allowUnresolved) {
                throw ValidationException::withMessages(['ndc' => $resolution->reason === NdcResolution::REASON_UNAVAILABLE
                    ? 'The FDA listing could not be reached. Try again, or save the fill without a match.'
                    : 'No FDA listing matches this NDC']);
            }
        }
        if ($printed === null) {
            throw ValidationException::withMessages([
                $strict ? 'ndc' : 'data.drug_as_printed' => 'The NDC did not resolve to one drug, so describe the drug as printed on the label (drug_as_printed).',
            ]);
        }

        return [
            'attributes' => $this->cleared(
                $resolution->status === NdcResolution::AMBIGUOUS ? PhrMedicationFill::NDC_AMBIGUOUS : PhrMedicationFill::NDC_UNRESOLVED,
                $resolution->ndcAsCaptured,
            ),
            'resolution' => $resolution,
        ];
    }

    /** @param array<string, mixed> $input */
    private function printed(array $input, ?PhrMedicationFill $existing): ?string
    {
        $value = array_key_exists('drug_as_printed', $input) ? $input['drug_as_printed'] : $existing?->drug_as_printed;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array<string, mixed> */
    private function cleared(string $status, ?string $captured): array
    {
        return [
            'ndc_status' => $status,
            'ndc_source' => null,
            'ndc_as_captured' => $captured,
            // The ten-digit form is always shown, so keep it whenever the input
            // fixes one, even without an FDA match.
            'ndc_10' => $ten = ($captured === null ? null : NdcNumber::toTenDigit($captured)),
            'ndc_11' => $ten === null ? null : NdcNumber::toNdc11($ten),
            'substance_names' => null,
            'strengths' => null,
            'brand_name' => null,
            'dosage_form' => null,
            'route' => null,
            'labeler_name' => null,
            'package_description' => null,
            'rxcui' => null,
            'fda_snapshot' => null,
            'fda_fetched_at' => null,
        ];
    }
}
