<?php

namespace App\Services\PHR\Fills;

/**
 * Decides which pharmacy claim each prescription fill belongs to, from one patient's current
 * data and nothing else. A pure function: no database, no clock, no model, and no reliance on
 * the order of its input, so the same data gives the same answer however it arrived.
 *
 * The claims this is for carry only a service date, plan codes and amounts: no drug, NDC, Rx
 * number, pharmacy or quantity. All there is to go on is the date, and that a drug's total
 * charge tends to recur. The answer is an inference, which is why it is recorded as evidence
 * and marked as automatic. There is no ground truth to measure it against.
 *
 * In order, each step working from what the previous ones left:
 *
 *  0. Net reversals. A negative claim cancels the earliest positive claim with the same service
 *     date and the opposite total charge. Neither is a candidate for anything afterwards.
 *  1. {@see self::RULE_SOLE_PAIR}: the only fill and the only live claim on a service date,
 *     unless the charge contradicts the drug (see below).
 *  2. {@see self::RULE_KNOWN_CHARGE}, repeated until nothing changes: the only open claim on the
 *     day whose charge is already known for this drug, for the only open fill of that drug on
 *     that day, where no other fill wants the same claim. All of a round's links are decided
 *     against the state at the start of the round and applied together, so no fill gets to go
 *     first. A person's or client's link teaches a charge too.
 *  3. {@see self::RULE_LAST_PAIR}: the last open fill and the last open claim of a day, unless
 *     the charge contradicts the drug. This is elimination, not recognition, so it teaches
 *     nothing: a wrong guess here must not spread to other days.
 *
 * What is left with a same-day claim goes to a person. What has no same-day claim is left alone,
 * since not every fill has an insurance claim. Nothing is ever linked across days.
 *
 * The contradiction guard: a charge contradicts a drug when it has recurred on one other drug, at
 * least {@see self::RECURRENCE} times, and has never been seen on this one. That is positive
 * evidence the claim belongs to a fill that was never recorded. A charge merely new for the drug
 * is no contradiction, because charges drift. Nor is one that several drugs happen to share once
 * each, because cheap generics often cost the same.
 */
final class FillEobLinkPlanner
{
    public const string RULE_SOLE_PAIR = 'sole_pair';

    public const string RULE_KNOWN_CHARGE = 'known_charge';

    public const string RULE_LAST_PAIR = 'last_pair';

    /** Two or more open fills of one drug on one day: nothing tells them apart. */
    public const string REASON_SAME_DRUG_TWICE = 'same_drug_twice';

    /** The only claim on offer carries a charge known for other drugs, never for this one. */
    public const string REASON_CHARGE_CONTRADICTED = 'charge_contradicted';

    /** Several open fills or claims, and what is known about the charges does not settle it. */
    public const string REASON_UNDECIDED = 'undecided';

    private const int RECURRENCE = 2;

    /**
     * @param  array<int, array{id: int, day: string, drug: ?string}>  $fills  live, not rejected
     * @param  array<int, array{id: int, day: ?string, charge: ?int, sort: string}>  $claims  pharmacy, not rejected; charge in cents; sort is a stable order that does not depend on row ids alone
     * @param  array<int, list<int>>  $manualLinks  fill id => claims a person or client linked it to; an empty list means a line of something other than a pharmacy claim
     * @param  array<int, int>  $dismissed  fills a person said have none of the claims on offer
     * @return array{
     *     links: array<int, array{claim_id: int, rule: string}>,
     *     open: array<int, array{reason: string, claim_ids: list<int>}>,
     *     counts: array<string, int>,
     * }
     */
    public function plan(array $fills, array $claims, array $manualLinks, array $dismissed): array
    {
        [$live, $netted, $unmatched] = $this->net($claims);
        $claimsById = [];
        foreach ($claims as $claim) {
            $claimsById[$claim['id']] = $claim;
        }

        // Fills a person or client has already answered for are not open, and their claims are taken.
        $settled = array_fill_keys($dismissed, true);
        $taken = [];
        /** @var array<int, array<string, int>> $seen charge => drug => times seen */
        $seen = [];
        $fillsById = [];
        foreach ($fills as $fill) {
            $fillsById[$fill['id']] = $fill;
        }
        foreach ($manualLinks as $fillId => $claimIds) {
            $settled[$fillId] = true;
            foreach (array_unique($claimIds) as $claimId) {
                $taken[$claimId] = true;
                $charge = $claimsById[$claimId]['charge'] ?? null;
                $drug = $fillsById[$fillId]['drug'] ?? null;
                if ($charge !== null && $charge > 0 && $drug !== null) {
                    $seen[$charge][$drug] = ($seen[$charge][$drug] ?? 0) + 1;
                }
            }
        }

        $fillsByDay = [];
        foreach ($fills as $fill) {
            $fillsByDay[$fill['day']][] = $fill;
        }
        $claimsByDay = [];
        foreach ($live as $claim) {
            $claimsByDay[(string) $claim['day']][] = $claim;
        }

        $links = $this->soleLinks($fillsByDay, $claimsByDay, $settled, $taken, $seen);
        $this->knownChargeLinks($fillsByDay, $claimsByDay, $settled, $taken, $seen, $links);
        $this->lastPairLinks($fillsByDay, $claimsByDay, $settled, $taken, $seen, $links);

        $open = [];
        $noCandidate = 0;
        foreach ($fillsByDay as $day => $dayFills) {
            $openFills = $this->openFills($dayFills, $settled, $links);
            $openClaims = $this->openClaims($claimsByDay[$day] ?? [], $taken, $links);
            foreach ($openFills as $fill) {
                if ($openClaims === []) {
                    $noCandidate++;

                    continue;
                }
                $open[$fill['id']] = [
                    'reason' => $this->reason($fill, $openFills, $openClaims, $seen),
                    'claim_ids' => array_column($openClaims, 'id'),
                ];
            }
        }

        $rules = array_count_values(array_column($links, 'rule'));

        return [
            'links' => $links,
            'open' => $open,
            'counts' => [
                'fills' => count($fills),
                'pharmacy_claims' => count($claims),
                'reversals_netted' => $netted,
                'reversals_unmatched' => $unmatched,
                'live_claims' => count($live),
                'settled_by_a_person_or_client' => count($settled),
                'linked_sole_pair' => $rules[self::RULE_SOLE_PAIR] ?? 0,
                'linked_known_charge' => $rules[self::RULE_KNOWN_CHARGE] ?? 0,
                'linked_last_pair' => $rules[self::RULE_LAST_PAIR] ?? 0,
                'flagged_for_a_person' => count($open),
                'no_same_day_claim' => $noCandidate,
            ],
        ];
    }

    /**
     * A reversal cancels the earliest matching claim, in the stable order: reversing a claim and
     * reprocessing it leaves the reprocessed claim live, whichever of the three arrived first.
     * A claim with no stated charge cannot be netted but is live: it may be a real dispensing,
     * so it stops the day from looking like a sole pair. A zero charge is no dispensing at all.
     *
     * @param  list<array{id: int, day: ?string, charge: ?int, sort: string}>  $claims
     * @return array{0: list<array{id: int, day: ?string, charge: ?int, sort: string}>, 1: int, 2: int}
     */
    private function net(array $claims): array
    {
        $dated = array_values(array_filter($claims, static fn (array $claim): bool => $claim['day'] !== null));
        usort($dated, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $positive = [];
        $live = [];
        foreach ($dated as $claim) {
            if ($claim['charge'] === null) {
                $live[$claim['id']] = $claim;
            } elseif ($claim['charge'] > 0) {
                $live[$claim['id']] = $claim;
                $positive[$claim['day'].'|'.$claim['charge']][] = $claim['id'];
            }
        }

        $netted = 0;
        $unmatched = 0;
        foreach ($dated as $claim) {
            if ($claim['charge'] === null || $claim['charge'] >= 0) {
                continue;
            }
            $key = $claim['day'].'|'.(-$claim['charge']);
            if (($positive[$key] ?? []) === []) {
                $unmatched++;

                continue;
            }
            unset($live[array_shift($positive[$key])]);
            $netted++;
        }

        return [array_values($live), $netted, $unmatched];
    }

    /**
     * Judged together, so one pair's guard never depends on another having been looked at first.
     *
     * @param  array<string, list<array{id: int, day: string, drug: ?string}>>  $fillsByDay
     * @param  array<string, list<array{id: int, day: ?string, charge: ?int, sort: string}>>  $claimsByDay
     * @param  array<int, true>  $settled
     * @param  array<int, true>  $taken
     * @param  array<int, array<string, int>>  $seen
     * @return array<int, array{claim_id: int, rule: string}>
     */
    private function soleLinks(array $fillsByDay, array $claimsByDay, array $settled, array $taken, array &$seen): array
    {
        $pairs = [];
        $support = $seen;
        foreach ($claimsByDay as $day => $dayClaims) {
            $dayFills = $fillsByDay[$day] ?? [];
            if (count($dayFills) !== 1 || count($dayClaims) !== 1) {
                continue;
            }
            [$fill, $claim] = [$dayFills[0], $dayClaims[0]];
            if (isset($settled[$fill['id']]) || isset($taken[$claim['id']]) || $claim['charge'] === null) {
                continue;
            }
            $pairs[] = [$fill, $claim];
            if ($fill['drug'] !== null) {
                $support[$claim['charge']][$fill['drug']] = ($support[$claim['charge']][$fill['drug']] ?? 0) + 1;
            }
        }

        $links = [];
        foreach ($pairs as [$fill, $claim]) {
            if ($this->contradicted($support, $fill['drug'], (int) $claim['charge'], 1)) {
                continue;
            }
            $links[$fill['id']] = ['claim_id' => $claim['id'], 'rule' => self::RULE_SOLE_PAIR];
            if ($fill['drug'] !== null) {
                $seen[(int) $claim['charge']][$fill['drug']] = ($seen[(int) $claim['charge']][$fill['drug']] ?? 0) + 1;
            }
        }

        return $links;
    }

    /**
     * @param  array<string, list<array{id: int, day: string, drug: ?string}>>  $fillsByDay
     * @param  array<string, list<array{id: int, day: ?string, charge: ?int, sort: string}>>  $claimsByDay
     * @param  array<int, true>  $settled
     * @param  array<int, true>  $taken
     * @param  array<int, array<string, int>>  $seen
     * @param  array<int, array{claim_id: int, rule: string}>  $links
     */
    private function knownChargeLinks(array $fillsByDay, array $claimsByDay, array $settled, array $taken, array &$seen, array &$links): void
    {
        do {
            $wanted = [];
            foreach ($fillsByDay as $day => $dayFills) {
                $openFills = $this->openFills($dayFills, $settled, $links);
                $openClaims = $this->openClaims($claimsByDay[$day] ?? [], $taken, $links);
                foreach ($openFills as $fill) {
                    if ($fill['drug'] === null) {
                        continue;
                    }
                    $hits = array_values(array_filter($openClaims, static fn (array $claim): bool => $claim['charge'] !== null && isset($seen[$claim['charge']][$fill['drug']])));
                    if (count($hits) === 1) {
                        $wanted[$hits[0]['id']][] = ['fill' => $fill, 'charge' => (int) $hits[0]['charge']];
                    }
                }
            }

            $linked = false;
            foreach ($wanted as $claimId => $suitors) {
                // Two fills of one drug, or two drugs that each know this charge: the data cannot say whose it is.
                if (count($suitors) !== 1) {
                    continue;
                }
                ['fill' => $fill, 'charge' => $charge] = $suitors[0];
                $links[$fill['id']] = ['claim_id' => $claimId, 'rule' => self::RULE_KNOWN_CHARGE];
                $seen[$charge][(string) $fill['drug']] = ($seen[$charge][(string) $fill['drug']] ?? 0) + 1;
                $linked = true;
            }
        } while ($linked);
    }

    /**
     * @param  array<string, list<array{id: int, day: string, drug: ?string}>>  $fillsByDay
     * @param  array<string, list<array{id: int, day: ?string, charge: ?int, sort: string}>>  $claimsByDay
     * @param  array<int, true>  $settled
     * @param  array<int, true>  $taken
     * @param  array<int, array<string, int>>  $seen
     * @param  array<int, array{claim_id: int, rule: string}>  $links
     */
    private function lastPairLinks(array $fillsByDay, array $claimsByDay, array $settled, array $taken, array $seen, array &$links): void
    {
        $found = [];
        foreach ($fillsByDay as $day => $dayFills) {
            $openFills = $this->openFills($dayFills, $settled, $links);
            $openClaims = $this->openClaims($claimsByDay[$day] ?? [], $taken, $links);
            if (count($openFills) !== 1 || count($openClaims) !== 1 || $openClaims[0]['charge'] === null) {
                continue;
            }
            if (! $this->contradicted($seen, $openFills[0]['drug'], (int) $openClaims[0]['charge'], 0)) {
                $found[$openFills[0]['id']] = ['claim_id' => $openClaims[0]['id'], 'rule' => self::RULE_LAST_PAIR];
            }
        }
        $links += $found;
    }

    /**
     * @param  list<array{id: int, day: string, drug: ?string}>  $dayFills
     * @param  array<int, true>  $settled
     * @param  array<int, array{claim_id: int, rule: string}>  $links
     * @return list<array{id: int, day: string, drug: ?string}>
     */
    private function openFills(array $dayFills, array $settled, array $links): array
    {
        return array_values(array_filter($dayFills, static fn (array $fill): bool => ! isset($settled[$fill['id']]) && ! isset($links[$fill['id']])));
    }

    /**
     * @param  list<array{id: int, day: ?string, charge: ?int, sort: string}>  $dayClaims
     * @param  array<int, true>  $taken
     * @param  array<int, array{claim_id: int, rule: string}>  $links
     * @return list<array{id: int, day: ?string, charge: ?int, sort: string}>
     */
    private function openClaims(array $dayClaims, array $taken, array $links): array
    {
        $linked = array_flip(array_column($links, 'claim_id'));

        return array_values(array_filter($dayClaims, static fn (array $claim): bool => ! isset($taken[$claim['id']]) && ! isset($linked[$claim['id']])));
    }

    /**
     * @param  array{id: int, day: string, drug: ?string}  $fill
     * @param  list<array{id: int, day: string, drug: ?string}>  $openFills
     */
    private function sameDrugCount(array $fill, array $openFills): int
    {
        return count(array_filter($openFills, static fn (array $other): bool => $other['drug'] === $fill['drug']));
    }

    /**
     * @param  array<int, array<string, int>>  $seen  charge => drug => times seen
     * @param  int  $self  how many of those sightings are the pair being judged
     */
    private function contradicted(array $seen, ?string $drug, int $charge, int $self): bool
    {
        if ($drug !== null && ($seen[$charge][$drug] ?? 0) - $self > 0) {
            return false;
        }
        foreach ($seen[$charge] ?? [] as $other => $times) {
            if ((string) $other !== $drug && $times >= self::RECURRENCE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{id: int, day: string, drug: ?string}  $fill
     * @param  list<array{id: int, day: string, drug: ?string}>  $openFills
     * @param  list<array{id: int, day: ?string, charge: ?int, sort: string}>  $openClaims
     * @param  array<int, array<string, int>>  $seen
     */
    private function reason(array $fill, array $openFills, array $openClaims, array $seen): string
    {
        if ($fill['drug'] !== null && $this->sameDrugCount($fill, $openFills) > 1) {
            return self::REASON_SAME_DRUG_TWICE;
        }
        if (count($openClaims) === 1 && $openClaims[0]['charge'] !== null && $this->contradicted($seen, $fill['drug'], (int) $openClaims[0]['charge'], 0)) {
            return self::REASON_CHARGE_CONTRADICTED;
        }

        return self::REASON_UNDECIDED;
    }
}
