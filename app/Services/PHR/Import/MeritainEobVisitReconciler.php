<?php

namespace App\Services\PHR\Import;

use App\Models\PhrEob;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @phpstan-type Encounter array{
 *   visit_date: string,
 *   provider_key: string,
 *   provider_name: string,
 *   codes: list<string>,
 *   eob_ids: non-empty-list<int>,
 *   claim_numbers: list<string>,
 *   source_document_id: int|null
 * }
 */
class MeritainEobVisitReconciler
{
    /**
     * @return array{candidates: int, claims: int, created: int, matched: int, updated: int, links: int}
     */
    public function reconcile(PhrPatient $patient, bool $dryRun = false): array
    {
        $encounters = collect($this->encounters($patient));
        $existing = PhrOfficeVisit::query()
            ->where('patient_id', $patient->id)
            ->orderBy('id')
            ->get();
        $encountersPerDate = $encounters->countBy('visit_date');
        $result = [
            'candidates' => $encounters->count(),
            'claims' => $encounters->sum(fn (array $encounter): int => count($encounter['eob_ids'])),
            'created' => 0,
            'matched' => 0,
            'updated' => 0,
            'links' => 0,
        ];

        foreach ($encounters as $encounter) {
            $visit = $this->matchingVisit($existing, $encounter, (int) $encountersPerDate[$encounter['visit_date']]);
            $existingCptCodes = $visit instanceof PhrOfficeVisit ? ($visit->cpt_codes ?? []) : [];
            $cptCodes = $this->mergeCptCodes($existingCptCodes, $encounter['codes']);
            if ($visit === null) {
                $result['created']++;
                $result['links'] += count($encounter['eob_ids']);
                if ($dryRun) {
                    continue;
                }

                $visit = PhrOfficeVisit::create([
                    'patient_id' => $patient->id,
                    'user_id' => $patient->owner_user_id,
                    'import_source' => MeritainEobImporter::IMPORT_SOURCE,
                    'external_id' => 'eob-visit:'.hash('sha256', $encounter['visit_date'].'|'.$encounter['provider_key']),
                    'source_document_id' => $encounter['source_document_id'],
                    'visit_date' => $encounter['visit_date'],
                    'visit_type' => $this->visitType($encounter['codes']),
                    'provider_name' => $this->providerDisplayName($encounter['provider_name']),
                    'cpt_codes' => $cptCodes,
                    'raw_text' => $this->evidenceNote($encounter['claim_numbers'], $encounter['codes']),
                ]);
                $existing->push($visit);
                $this->attachEobs($visit, $patient, $encounter['eob_ids']);

                continue;
            }

            $result['matched']++;
            if ($this->cptCodesDiffer($visit->cpt_codes ?? [], $cptCodes)) {
                $result['updated']++;
                if (! $dryRun) {
                    $visit->update(['cpt_codes' => $cptCodes]);
                }
            }

            $missingLinks = DB::table('phr_office_visit_eobs')
                ->where('office_visit_id', $visit->id)
                ->whereIn('eob_id', $encounter['eob_ids'])
                ->count();
            $result['links'] += count($encounter['eob_ids']) - $missingLinks;
            if (! $dryRun) {
                $this->attachEobs($visit, $patient, $encounter['eob_ids']);
            }
        }

        return $result;
    }

    /**
     * @return list<Encounter>
     */
    private function encounters(PhrPatient $patient): array
    {
        $eobs = PhrEob::query()
            ->with(['lines' => fn ($query) => $query->orderBy('line_number')])
            ->where('patient_id', $patient->id)
            ->where('import_source', MeritainEobImporter::IMPORT_SOURCE)
            ->where('claim_type', 'medical')
            ->orderBy('processed_date')
            ->orderBy('id')
            ->get();
        $encounters = [];

        foreach ($eobs as $eob) {
            $visitLines = $eob->lines->filter(fn ($line): bool => $this->isVisitCode($line->procedure_code));
            foreach ($visitLines->groupBy(fn ($line): string => $line->service_start?->format('Y-m-d') ?? '') as $date => $lines) {
                $date = (string) $date;
                if ($date === '' || trim((string) $eob->provider_name) === '') {
                    continue;
                }

                $providerKey = $this->providerKey((string) $eob->provider_name);
                $key = $date.'|'.$providerKey;
                $encounters[$key] ??= [
                    'visit_date' => $date,
                    'provider_key' => $providerKey,
                    'provider_name' => (string) $eob->provider_name,
                    'codes' => [],
                    'eob_ids' => [],
                    'claim_numbers' => [],
                    'source_document_id' => $eob->source_document_id,
                    'processed_date' => $eob->processed_date?->format('Y-m-d'),
                ];
                $encounters[$key]['codes'] = array_values(array_unique([
                    ...$encounters[$key]['codes'],
                    ...$lines->pluck('procedure_code')->map(fn (string $code): string => strtoupper($code))->all(),
                ]));
                $encounters[$key]['eob_ids'][] = $eob->id;
                if ($eob->claim_number !== null) {
                    $encounters[$key]['claim_numbers'][] = $eob->claim_number;
                }
                if (($eob->processed_date?->format('Y-m-d') ?? '') >= ($encounters[$key]['processed_date'] ?? '')) {
                    $encounters[$key]['source_document_id'] = $eob->source_document_id;
                    $encounters[$key]['processed_date'] = $eob->processed_date?->format('Y-m-d');
                }
            }
        }

        return collect($encounters)
            ->map(function (array $encounter): array {
                sort($encounter['codes'], SORT_STRING);
                $encounter['eob_ids'] = array_values(array_unique($encounter['eob_ids']));
                $encounter['claim_numbers'] = array_values(array_unique($encounter['claim_numbers']));
                unset($encounter['processed_date']);

                return $encounter;
            })
            ->sortBy(fn (array $encounter): string => $encounter['visit_date'].'|'.$encounter['provider_key'])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PhrOfficeVisit>  $existing
     * @param  Encounter  $encounter
     */
    private function matchingVisit(Collection $existing, array $encounter, int $encountersOnDate): ?PhrOfficeVisit
    {
        $onDate = $existing->filter(fn (PhrOfficeVisit $visit): bool => $visit->visit_date?->format('Y-m-d') === $encounter['visit_date']);
        $providerMatches = $onDate->filter(
            fn (PhrOfficeVisit $visit): bool => $this->providerKey((string) $visit->provider_name) === $encounter['provider_key']
        );
        if ($providerMatches->count() === 1) {
            return $providerMatches->first();
        }

        return $onDate->count() === 1 && $encountersOnDate === 1 ? $onDate->first() : null;
    }

    private function isVisitCode(string $code): bool
    {
        return preg_match('/^(?:9920[2-5]|9921[1-5]|9938[1-7]|9939[1-7]|99417)$/', strtoupper($code)) === 1;
    }

    private function providerKey(string $provider): string
    {
        $provider = strtoupper($provider);
        $provider = (string) preg_replace('/\b(?:MD|DO|PHD|NP|PA-C)\b/', ' ', $provider);

        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', $provider));
    }

    private function providerDisplayName(string $provider): string
    {
        return Str::title(Str::lower(trim($provider)));
    }

    /** @param array<int, string> $codes */
    private function visitType(array $codes): string
    {
        if (array_any($codes, fn (string $code): bool => preg_match('/^993[89][1-7]$/', $code) === 1)) {
            return 'preventive visit';
        }
        if (array_any($codes, fn (string $code): bool => preg_match('/^9920[2-5]$/', $code) === 1)) {
            return 'new patient';
        }

        return 'follow-up';
    }

    /**
     * @param  array<int, mixed>  $existing
     * @param  array<int, string>  $newCodes
     * @return array<int, array{code: string, description: string}>
     */
    private function mergeCptCodes(array $existing, array $newCodes): array
    {
        $codes = [];
        foreach ($existing as $entry) {
            if (! is_array($entry) || ! isset($entry['code']) || ! is_string($entry['code']) || trim($entry['code']) === '') {
                continue;
            }
            $code = strtoupper($entry['code']);
            $description = isset($entry['description']) && is_string($entry['description'])
                ? $entry['description']
                : $this->codeDescription($code);
            $codes[$code] = ['code' => $code, 'description' => $description];
        }
        foreach ($newCodes as $code) {
            $code = strtoupper($code);
            $codes[$code] ??= ['code' => $code, 'description' => $this->codeDescription($code)];
        }
        ksort($codes, SORT_STRING);

        return array_values($codes);
    }

    /**
     * @param  array<int, array<string, string>>  $before
     * @param  array<int, array<string, string>>  $after
     */
    private function cptCodesDiffer(array $before, array $after): bool
    {
        return json_encode($before) !== json_encode($after);
    }

    private function codeDescription(string $code): string
    {
        return match (true) {
            preg_match('/^9920[2-5]$/', $code) === 1 => 'Office or outpatient E/M, new patient',
            preg_match('/^9921[1-5]$/', $code) === 1 => 'Office or outpatient E/M, established patient',
            preg_match('/^9938[1-7]$/', $code) === 1 => 'Initial preventive medicine evaluation',
            preg_match('/^9939[1-7]$/', $code) === 1 => 'Periodic preventive medicine evaluation',
            $code === '99417' => 'Prolonged outpatient E/M service',
            default => 'E/M service documented by EOB',
        };
    }

    /**
     * @param  array<int, string>  $claimNumbers
     * @param  array<int, string>  $codes
     */
    private function evidenceNote(array $claimNumbers, array $codes): string
    {
        return sprintf(
            'Backfilled from Meritain EOB claim(s) %s. The EOB confirms billed E/M code(s) %s but does not contain a clinical note.',
            implode(', ', $claimNumbers),
            implode(', ', $codes),
        );
    }

    /** @param array<int, int> $eobIds */
    private function attachEobs(PhrOfficeVisit $visit, PhrPatient $patient, array $eobIds): void
    {
        $now = now();
        foreach ($eobIds as $eobId) {
            DB::table('phr_office_visit_eobs')->insertOrIgnore([
                'patient_id' => $patient->id,
                'office_visit_id' => $visit->id,
                'eob_id' => $eobId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
