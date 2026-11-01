<?php

namespace App\Services\PHR\Ndc;

use Illuminate\Support\Carbon;

/**
 * Resolves NDCs and searches drugs through the openFDA packaging directory.
 *
 * Deterministic apart from what openFDA lists: an eleven-digit billing NDC
 * becomes every ten-digit reading, each checked against
 * `packaging.package_ndc`, and the answer is `resolved` (exactly one exists),
 * `ambiguous` (several do; a person picks) or `unresolved`.
 */
class NdcLookupService
{
    /** Most candidates ndc_search returns. */
    public const int SEARCH_LIMIT = 25;

    public function __construct(private readonly OpenFdaClient $client) {}

    public function lookup(string $ndc): NdcResolution
    {
        $candidates = NdcNumber::packageCandidates($ndc);
        if ($candidates === []) {
            return new NdcResolution(NdcResolution::UNRESOLVED, $ndc, [], NdcResolution::REASON_INVALID);
        }

        $found = [];
        $unavailable = false;
        foreach ($candidates as $candidate) {
            try {
                $entry = $this->client->findPackage($candidate);
            } catch (OpenFdaUnavailableException) {
                $unavailable = true;

                continue;
            }
            if ($entry !== null) {
                $found[] = $this->candidateFor($entry, $candidate);
            }
        }

        // A missing answer for one reading means the others cannot be called
        // the only match, so an outage never produces a confident result.
        if ($unavailable && count($found) < 2) {
            return new NdcResolution(NdcResolution::UNRESOLVED, $ndc, $found, NdcResolution::REASON_UNAVAILABLE);
        }
        if (count($found) === 1) {
            return new NdcResolution(NdcResolution::RESOLVED, $ndc, $found);
        }
        if (count($found) > 1) {
            return new NdcResolution(NdcResolution::AMBIGUOUS, $ndc, $found);
        }

        return new NdcResolution(NdcResolution::UNRESOLVED, $ndc, [], NdcResolution::REASON_NOT_FOUND);
    }

    /**
     * The exact package a person or agent chose among ambiguous candidates.
     * It resolves only if openFDA lists that ten-digit package NDC.
     */
    public function lookupChosen(string $ndc10, string $ndcAsCaptured): NdcResolution
    {
        $parsed = NdcNumber::parse($ndc10);
        if (! $parsed['valid'] || strlen($parsed['digits']) !== 10 || ! $parsed['hyphenated']) {
            return new NdcResolution(NdcResolution::UNRESOLVED, $ndcAsCaptured, [], NdcResolution::REASON_INVALID);
        }
        $package = NdcNumber::packageCandidates($ndc10)[0];
        try {
            $entry = $this->client->findPackage($package);
        } catch (OpenFdaUnavailableException) {
            return new NdcResolution(NdcResolution::UNRESOLVED, $ndcAsCaptured, [], NdcResolution::REASON_UNAVAILABLE);
        }
        if ($entry === null) {
            return new NdcResolution(NdcResolution::UNRESOLVED, $ndcAsCaptured, [], NdcResolution::REASON_NOT_FOUND);
        }

        return new NdcResolution(NdcResolution::RESOLVED, $ndcAsCaptured, [$this->candidateFor($entry, $package)]);
    }

    /**
     * Ranked package candidates for a drug whose NDC is missing or unreadable.
     * Never picks one: the caller shows the candidates to a person.
     *
     * @param  array{name: string, strength?: ?string, dosage_form?: ?string, route?: ?string, labeler?: ?string, package_size?: ?string}  $criteria
     * @return array{status: string, reason: ?string, candidates: list<array<string, mixed>>}
     */
    public function search(array $criteria): array
    {
        $name = $this->phrase($criteria['name']);
        $strength = $this->strengthPhrase($criteria['strength'] ?? null);
        $form = $this->phrase($criteria['dosage_form'] ?? null);
        $route = $this->phrase($criteria['route'] ?? null);
        $labeler = $this->phrase($criteria['labeler'] ?? null);
        $size = $this->phrase($criteria['package_size'] ?? null);

        $nameClause = $name === null ? null : '(brand_name:"'.$name.'" OR generic_name:"'.$name.'" OR active_ingredients.name:"'.$name.'")';
        $clauses = array_values(array_filter([
            $nameClause,
            $strength === null ? null : $this->strengthClause($strength),
            $form === null ? null : 'dosage_form:"'.$form.'"',
            $route === null ? null : 'route:"'.$route.'"',
            $labeler === null ? null : 'labeler_name:"'.$labeler.'"',
            $size === null ? null : 'packaging.description:"'.$size.'"',
        ]));
        if ($nameClause === null) {
            return ['status' => 'unresolved', 'reason' => NdcResolution::REASON_INVALID, 'candidates' => []];
        }

        try {
            $products = $this->client->search(implode(' AND ', $clauses), self::SEARCH_LIMIT * 2);
            // Labels are read imperfectly ("TAB" for "tablet"), so an over-constrained
            // search falls back to the name and strength, and ranking sorts out the rest.
            if ($products === [] && count($clauses) > 2) {
                $products = $this->client->search($nameClause, self::SEARCH_LIMIT * 2);
            }
        } catch (OpenFdaUnavailableException) {
            return ['status' => 'unresolved', 'reason' => NdcResolution::REASON_UNAVAILABLE, 'candidates' => []];
        }

        $fetchedAt = Carbon::now()->toIso8601String();
        $ranked = [];
        foreach ($products as $product) {
            foreach ((array) ($product['packaging'] ?? []) as $package) {
                if (! is_array($package) || ! isset($package['package_ndc'])) {
                    continue;
                }
                $candidate = NdcCandidate::fromProduct($product, $package, $fetchedAt);
                [$score, $reasons] = $this->score($candidate, $criteria);
                $ranked[] = ['candidate' => $candidate, 'score' => $score, 'reasons' => $reasons];
            }
        }
        usort($ranked, static fn (array $a, array $b): int => [$b['score'], $a['candidate']->ndc10] <=> [$a['score'], $b['candidate']->ndc10]);

        return [
            'status' => $ranked === [] ? 'unresolved' : 'candidates',
            'reason' => $ranked === [] ? NdcResolution::REASON_NOT_FOUND : null,
            'candidates' => array_map(static fn (array $row): array => [
                ...$row['candidate']->toArray($row['reasons']),
                'score' => $row['score'],
            ], array_slice($ranked, 0, self::SEARCH_LIMIT)),
        ];
    }

    /**
     * @param  array{name: string, strength?: ?string, dosage_form?: ?string, route?: ?string, labeler?: ?string, package_size?: ?string}  $criteria
     * @return array{0: int, 1: list<string>}
     */
    private function score(NdcCandidate $candidate, array $criteria): array
    {
        $drug = $candidate->drug;
        $score = 0;
        $reasons = [];
        $norm = static fn (?string $value): string => (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) $value));

        $wanted = $norm($criteria['name']);
        $names = array_merge(
            [$drug['brand_name'] ?? null, $candidate->genericName()],
            (array) ($drug['substance_names'] ?? []),
        );
        $exact = false;
        $partial = false;
        foreach ($names as $name) {
            $name = $norm(is_string($name) ? $name : null);
            if ($name === '' || $wanted === '') {
                continue;
            }
            $exact = $exact || $name === $wanted;
            $partial = $partial || str_contains($name, $wanted) || str_contains($wanted, $name);
        }
        if ($exact) {
            $score += 30;
            $reasons[] = 'drug name matches';
        } elseif ($partial) {
            $score += 15;
            $reasons[] = 'drug name partly matches';
        }

        $strength = $norm($this->strengthPhrase($criteria['strength'] ?? null));
        if ($strength !== '') {
            foreach ((array) ($drug['strengths'] ?? []) as $listed) {
                $listed = $norm(preg_replace('#/1$#', '', (string) $listed));
                if ($listed === $strength || str_starts_with($listed, $strength)) {
                    $score += 25;
                    $reasons[] = 'strength matches';
                    break;
                }
            }
        }

        $form = $norm($criteria['dosage_form'] ?? null);
        $listedForm = $norm($drug['dosage_form'] ?? null);
        if ($form !== '' && $listedForm !== '') {
            if ($form === $listedForm) {
                $score += 15;
                $reasons[] = 'dosage form matches';
            } elseif (str_contains($listedForm, $form)) {
                $score += 8;
                $reasons[] = 'dosage form partly matches';
            }
        }

        $route = $norm($criteria['route'] ?? null);
        if ($route !== '' && str_contains($norm($drug['route'] ?? null), $route)) {
            $score += 5;
            $reasons[] = 'route matches';
        }

        $labeler = $norm($criteria['labeler'] ?? null);
        if ($labeler !== '' && str_contains($norm($drug['labeler_name'] ?? null), $labeler)) {
            $score += 15;
            $reasons[] = 'labeler matches';
        }

        $size = trim((string) ($criteria['package_size'] ?? ''));
        if ($size !== '' && preg_match('/\A\d+\z/', $size) === 1
            && preg_match('/\A\s*(\d+)\s/', (string) ($drug['package_description'] ?? ''), $count) === 1 && $count[1] === $size) {
            $score += 15;
            $reasons[] = 'package size matches';
        }

        return [$score, $reasons];
    }

    /**
     * @param  array{product: array<string, mixed>, fetched_at: string}  $entry
     */
    private function candidateFor(array $entry, string $packageNdc): NdcCandidate
    {
        foreach ((array) ($entry['product']['packaging'] ?? []) as $package) {
            if (is_array($package) && strcasecmp((string) ($package['package_ndc'] ?? ''), $packageNdc) === 0) {
                return NdcCandidate::fromProduct($entry['product'], $package, $entry['fetched_at']);
            }
        }

        return NdcCandidate::fromProduct($entry['product'], ['package_ndc' => $packageNdc], $entry['fetched_at']);
    }

    /** A value safe inside an openFDA quoted phrase. */
    private function phrase(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/["\\\\()]+|\s+/', ' ', $value));

        return $value === '' ? null : $value;
    }

    /**
     * openFDA indexes a strength as one value ("10 mg/1", "100 mg/mL"), so a bare
     * "10 mg" matches nothing; try the denominators strengths are listed with.
     */
    private function strengthClause(string $strength): string
    {
        if (str_contains($strength, '/')) {
            return 'active_ingredients.strength:"'.$strength.'"';
        }

        return '('.implode(' OR ', array_map(
            static fn (string $per): string => 'active_ingredients.strength:"'.$strength.'/'.$per.'"',
            ['1', 'mL', 'g', 'L'],
        )).')';
    }

    /** "10mg" and "10 MG" both become "10 mg", the form openFDA tokenizes. */
    private function strengthPhrase(?string $value): ?string
    {
        $value = $this->phrase($value);
        if ($value === null) {
            return null;
        }

        return mb_strtolower((string) preg_replace('/(\d)([A-Za-z])/', '$1 $2', $value));
    }
}
