<?php

namespace App\Services\PHR\Import;

use App\Models\PhrEob;
use App\Models\PhrPatient;
use App\Models\PhrProcedure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @phpstan-type AllergyProcedure array{
 *   performed_on: string,
 *   provider_key: string,
 *   provider_name: string,
 *   cpt_code: '95115'|'95117'|'95165',
 *   eob_ids: non-empty-list<int>,
 *   claim_numbers: list<string>,
 *   source_document_id: int|null
 * }
 */
class MeritainEobAllergyProcedureReconciler
{
    private const array CODES = ['95115', '95117', '95165'];

    /**
     * @return array{candidates: int, claims: int, created: int, matched: int, links: int}
     */
    public function reconcile(PhrPatient $patient, bool $dryRun = false): array
    {
        $candidates = collect($this->candidates($patient));
        $existing = PhrProcedure::query()
            ->where('patient_id', $patient->id)
            ->orderBy('id')
            ->get();
        $result = [
            'candidates' => $candidates->count(),
            'claims' => $candidates->sum(fn (array $candidate): int => count($candidate['eob_ids'])),
            'created' => 0,
            'matched' => 0,
            'links' => 0,
        ];

        foreach ($candidates as $candidate) {
            $procedure = $this->matchingProcedure($existing, $candidate);
            if ($procedure === null) {
                $result['created']++;
                $result['links'] += count($candidate['eob_ids']);
                if ($dryRun) {
                    continue;
                }

                $procedure = PhrProcedure::create([
                    'patient_id' => $patient->id,
                    'user_id' => $patient->owner_user_id,
                    'import_source' => MeritainEobImporter::IMPORT_SOURCE,
                    'external_id' => 'eob-allergy-procedure:'.hash(
                        'sha256',
                        $candidate['performed_on'].'|'.$candidate['provider_key'].'|'.$candidate['cpt_code']
                    ),
                    'source_document_id' => $candidate['source_document_id'],
                    'name' => $this->name($candidate['cpt_code']),
                    'cpt_code' => $candidate['cpt_code'],
                    'performed_on' => $candidate['performed_on'],
                    'performer_name' => Str::title(Str::lower($candidate['provider_name'])),
                    'status' => 'completed',
                    'notes' => $this->doseLimitation($candidate['cpt_code']),
                    'raw_text' => $this->evidenceNote($candidate['claim_numbers'], $candidate['cpt_code']),
                ]);
                $existing->push($procedure);
                $this->attachEobs($procedure, $patient, $candidate['eob_ids']);

                continue;
            }

            $result['matched']++;
            $existingLinks = DB::table('phr_procedure_eobs')
                ->where('procedure_id', $procedure->id)
                ->whereIn('eob_id', $candidate['eob_ids'])
                ->count();
            $result['links'] += count($candidate['eob_ids']) - $existingLinks;
            if (! $dryRun) {
                $this->attachEobs($procedure, $patient, $candidate['eob_ids']);
            }
        }

        return $result;
    }

    /** @return list<AllergyProcedure> */
    private function candidates(PhrPatient $patient): array
    {
        $eobs = PhrEob::query()
            ->with(['lines' => fn ($query) => $query->orderBy('line_number')])
            ->where('patient_id', $patient->id)
            ->where('import_source', MeritainEobImporter::IMPORT_SOURCE)
            ->where('claim_type', 'medical')
            ->orderBy('processed_date')
            ->orderBy('id')
            ->get();
        $candidates = [];

        foreach ($eobs as $eob) {
            foreach ($eob->lines->whereIn('procedure_code', self::CODES) as $line) {
                $date = $line->service_start?->format('Y-m-d');
                if ($date === null || trim((string) $eob->provider_name) === '') {
                    continue;
                }

                $code = $line->procedure_code;
                $providerKey = $this->providerKey((string) $eob->provider_name);
                $key = $date.'|'.$providerKey.'|'.$code;
                $candidates[$key] ??= [
                    'performed_on' => $date,
                    'provider_key' => $providerKey,
                    'provider_name' => (string) $eob->provider_name,
                    'cpt_code' => $code,
                    'eob_ids' => [],
                    'claim_numbers' => [],
                    'source_document_id' => $eob->source_document_id,
                    'processed_date' => $eob->processed_date?->format('Y-m-d'),
                ];
                $candidates[$key]['eob_ids'][] = $eob->id;
                if ($eob->claim_number !== null) {
                    $candidates[$key]['claim_numbers'][] = $eob->claim_number;
                }
                if (($eob->processed_date?->format('Y-m-d') ?? '') >= ($candidates[$key]['processed_date'] ?? '')) {
                    $candidates[$key]['source_document_id'] = $eob->source_document_id;
                    $candidates[$key]['processed_date'] = $eob->processed_date?->format('Y-m-d');
                }
            }
        }

        return collect($candidates)
            ->map(function (array $candidate): array {
                $candidate['eob_ids'] = array_values(array_unique($candidate['eob_ids']));
                $candidate['claim_numbers'] = array_values(array_unique($candidate['claim_numbers']));
                unset($candidate['processed_date']);

                return $candidate;
            })
            ->sortBy(fn (array $candidate): string => $candidate['performed_on'].'|'.$candidate['cpt_code'].'|'.$candidate['provider_key'])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PhrProcedure>  $existing
     * @param  AllergyProcedure  $candidate
     */
    private function matchingProcedure(Collection $existing, array $candidate): ?PhrProcedure
    {
        $matches = $existing->filter(fn (PhrProcedure $procedure): bool => $procedure->performed_on?->format('Y-m-d') === $candidate['performed_on']
            && $procedure->cpt_code === $candidate['cpt_code']);
        if ($matches->count() <= 1) {
            return $matches->first();
        }

        return $matches->first(
            fn (PhrProcedure $procedure): bool => $this->providerKey((string) $procedure->performer_name) === $candidate['provider_key']
        );
    }

    private function providerKey(string $provider): string
    {
        $provider = strtoupper($provider);
        $provider = (string) preg_replace('/\b(?:MD|DO|PHD|NP|PA-C)\b/', ' ', $provider);

        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', $provider));
    }

    private function name(string $code): string
    {
        return match ($code) {
            '95115' => 'Allergy immunotherapy administration (single injection)',
            '95117' => 'Allergy immunotherapy administration (multiple injections)',
            '95165' => 'Allergen immunotherapy extract preparation',
            default => 'Allergy immunotherapy service',
        };
    }

    private function doseLimitation(string $code): string
    {
        return match ($code) {
            '95115' => 'The EOB confirms a single allergy-injection administration. Injection volume, concentration, antigen, and vial dose are not stated.',
            '95117' => 'The EOB confirms administration of two or more allergy injections. Injection volumes, concentrations, antigens, and vial doses are not stated.',
            '95165' => 'The EOB confirms allergen-immunotherapy extract preparation or provision. Prepared quantity, concentration, formulation, and vial dose are not stated.',
            default => 'The EOB does not state dosage details.',
        };
    }

    /** @param list<string> $claimNumbers */
    private function evidenceNote(array $claimNumbers, string $code): string
    {
        return sprintf(
            'Backfilled from Meritain EOB claim(s) %s using CPT %s; the EOB contains billing evidence rather than a clinical administration note.',
            implode(', ', $claimNumbers),
            $code,
        );
    }

    /** @param non-empty-list<int> $eobIds */
    private function attachEobs(PhrProcedure $procedure, PhrPatient $patient, array $eobIds): void
    {
        $now = now();
        foreach ($eobIds as $eobId) {
            DB::table('phr_procedure_eobs')->insertOrIgnore([
                'patient_id' => $patient->id,
                'procedure_id' => $procedure->id,
                'eob_id' => $eobId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
