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
 *   codes: array<string, string>,
 *   eob_ids: non-empty-list<int>,
 *   claim_numbers: list<string>,
 *   source_document_id: int|null
 * }
 */
class DeltaDentalEobVisitReconciler
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
            $existingCodes = $visit instanceof PhrOfficeVisit ? ($visit->cpt_codes ?? []) : [];
            $codes = $this->mergeCodes($existingCodes, $encounter['codes']);
            if ($visit === null) {
                $result['created']++;
                $result['links'] += count($encounter['eob_ids']);
                if ($dryRun) {
                    continue;
                }

                $visit = PhrOfficeVisit::create([
                    'patient_id' => $patient->id,
                    'user_id' => $patient->owner_user_id,
                    'import_source' => DeltaDentalEobImporter::IMPORT_SOURCE,
                    'external_id' => 'eob-dental-visit:'.hash('sha256', $encounter['visit_date'].'|'.$encounter['provider_key']),
                    'source_document_id' => $encounter['source_document_id'],
                    'visit_date' => $encounter['visit_date'],
                    'visit_type' => $this->visitType(array_keys($encounter['codes'])),
                    'provider_name' => $this->providerDisplayName($encounter['provider_name']),
                    'provider_specialty' => 'Dentistry',
                    'cpt_codes' => $codes,
                    'raw_text' => $this->evidenceNote($encounter['claim_numbers'], array_keys($encounter['codes'])),
                ]);
                $existing->push($visit);
                $this->attachEobs($visit, $patient, $encounter['eob_ids']);

                continue;
            }

            $result['matched']++;
            if (json_encode($visit->cpt_codes ?? []) !== json_encode($codes)) {
                $result['updated']++;
                if (! $dryRun) {
                    $visit->update(['cpt_codes' => $codes]);
                }
            }

            $existingLinks = DB::table('phr_office_visit_eobs')
                ->where('office_visit_id', $visit->id)
                ->whereIn('eob_id', $encounter['eob_ids'])
                ->count();
            $result['links'] += count($encounter['eob_ids']) - $existingLinks;
            if (! $dryRun) {
                $this->attachEobs($visit, $patient, $encounter['eob_ids']);
            }
        }

        return $result;
    }

    /** @return list<Encounter> */
    private function encounters(PhrPatient $patient): array
    {
        $eobs = PhrEob::query()
            ->with(['lines' => fn ($query) => $query->orderBy('line_number')])
            ->where('patient_id', $patient->id)
            ->where('import_source', DeltaDentalEobImporter::IMPORT_SOURCE)
            ->where('claim_type', 'dental')
            ->orderBy('submission_date')
            ->orderBy('id')
            ->get();
        $encounters = [];

        foreach ($eobs as $eob) {
            foreach ($eob->lines->groupBy(fn ($line): string => $line->service_start?->format('Y-m-d') ?? '') as $date => $lines) {
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
                ];
                foreach ($lines as $line) {
                    $encounters[$key]['codes'][strtoupper($line->procedure_code)] = $line->description ?: 'Dental service documented by EOB';
                }
                $encounters[$key]['eob_ids'][] = $eob->id;
                if ($eob->claim_number !== null) {
                    $encounters[$key]['claim_numbers'][] = $eob->claim_number;
                }
            }
        }

        return collect($encounters)
            ->map(function (array $encounter): array {
                ksort($encounter['codes'], SORT_STRING);
                $encounter['eob_ids'] = array_values(array_unique($encounter['eob_ids']));
                $encounter['claim_numbers'] = array_values(array_unique($encounter['claim_numbers']));

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

    private function providerKey(string $provider): string
    {
        $provider = strtoupper($provider);
        $provider = (string) preg_replace('/\b(?:DDS|DMD)\b/', ' ', $provider);

        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', $provider));
    }

    private function providerDisplayName(string $provider): string
    {
        return Str::title(Str::lower(trim($provider)));
    }

    /** @param list<string> $codes */
    private function visitType(array $codes): string
    {
        if (in_array('D1110', $codes, true) && array_intersect(['D0120', 'D0150'], $codes) !== []) {
            return 'dental examination and cleaning';
        }
        if (in_array('D1110', $codes, true)) {
            return 'dental cleaning';
        }

        return 'dental visit';
    }

    /**
     * @param  array<int, mixed>  $existing
     * @param  array<string, string>  $newCodes
     * @return list<array{code: string, description: string}>
     */
    private function mergeCodes(array $existing, array $newCodes): array
    {
        $codes = [];
        foreach ($existing as $entry) {
            if (is_array($entry) && isset($entry['code']) && is_string($entry['code']) && trim($entry['code']) !== '') {
                $code = strtoupper($entry['code']);
                $codes[$code] = [
                    'code' => $code,
                    'description' => isset($entry['description']) && is_string($entry['description'])
                        ? $entry['description']
                        : 'Dental service documented by EOB',
                ];
            }
        }
        foreach ($newCodes as $code => $description) {
            $codes[$code] ??= ['code' => $code, 'description' => $description];
        }
        ksort($codes, SORT_STRING);

        return array_values($codes);
    }

    /**
     * @param  list<string>  $claims
     * @param  list<string>  $codes
     */
    private function evidenceNote(array $claims, array $codes): string
    {
        return sprintf(
            'Backfilled from Delta Dental EOB claim(s) %s. The EOB confirms billed CDT code(s) %s but does not contain a clinical note.',
            implode(', ', $claims),
            implode(', ', $codes),
        );
    }

    /** @param list<int> $eobIds */
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
