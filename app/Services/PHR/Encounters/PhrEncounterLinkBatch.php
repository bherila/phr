<?php

namespace App\Services\PHR\Encounters;

use App\Models\PhrOfficeVisit;
use App\Support\PHR\PhrReviewStatus;

/**
 * Link several records to one visit (#449), each through PhrEncounterLinks::link in its own
 * transaction, so one refusal leaves the others linked. Built for the visit page's attach
 * and for confirming suggested links (#450): the rules, and the side effects, are exactly a
 * single link's. A link is evidence: it changes no record's columns, so no version, review
 * status, review count or change-feed entry moves, on this path as on the agent's.
 *
 * Refusals that concern the visit (missing, rejected) are the whole request's and are
 * thrown before anything is linked; refusals that concern one record are that record's
 * outcome.
 */
final class PhrEncounterLinkBatch
{
    /** Most links one request may carry. */
    public const int MAX_LINKS = 100;

    public const string REFUSED = 'refused';

    /** The record is not the patient's, or was retracted or deleted. */
    public const string NOT_FOUND = 'not_found';

    public function __construct(private PhrEncounterLinks $links) {}

    /**
     * @param  list<array{type: string, id: int}>  $items
     * @return list<array{type: string, id: int, outcome: string, reason?: string, message?: string, linked_visit?: array{id: int, date: string|null, visit_type: string|null}|null}>
     */
    public function link(int $patientId, int $visitId, array $items): array
    {
        $visit = PhrOfficeVisit::query()->where('patient_id', $patientId)->whereNull('retracted_at')->find($visitId)
            ?? throw new EncounterLinkTargetMissing(EncounterLinkTargetMissing::VISIT, 'Office visit not found.');
        if ($visit->review_status === PhrReviewStatus::REJECTED) {
            throw new EncounterLinkRefused(EncounterLinkRefused::VISIT_REJECTED, 'A rejected visit cannot take new links.');
        }

        $results = [];
        foreach ($items as $item) {
            $results[] = $this->linkOne($patientId, $visitId, $item['type'], $item['id']);
        }

        return $this->withLinkedVisits($patientId, $results);
    }

    /**
     * @return array{type: string, id: int, outcome: string, reason?: string, message?: string, linked_visit_id?: int|null}
     */
    private function linkOne(int $patientId, int $visitId, string $type, int $recordId): array
    {
        $result = ['type' => $type, 'id' => $recordId];
        try {
            return [...$result, 'outcome' => $this->links->link($patientId, $visitId, $type, $recordId)];
        } catch (EncounterLinkTargetMissing $missing) {
            if ($missing->target === EncounterLinkTargetMissing::VISIT) {
                throw $missing;
            }

            return [...$result, 'outcome' => self::REFUSED, 'reason' => self::NOT_FOUND, 'message' => $missing->getMessage()];
        } catch (EncounterLinkRefused $refused) {
            if ($refused->reason === EncounterLinkRefused::VISIT_REJECTED) {
                throw $refused;
            }

            return [...$result, 'outcome' => self::REFUSED, 'reason' => $refused->reason, 'message' => $refused->getMessage(), 'linked_visit_id' => $refused->linkedVisitId];
        }
    }

    /**
     * Name the visit each record linked elsewhere is on, so the person can find it.
     *
     * @param  list<array{type: string, id: int, outcome: string, reason?: string, message?: string, linked_visit_id?: int|null}>  $results
     * @return list<array{type: string, id: int, outcome: string, reason?: string, message?: string, linked_visit?: array{id: int, date: string|null, visit_type: string|null}|null}>
     */
    private function withLinkedVisits(int $patientId, array $results): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $result): ?int => $result['linked_visit_id'] ?? null, $results))));
        $visits = PhrLinkableRecords::visitSummaries($patientId, $ids);

        return array_map(static function (array $result) use ($visits): array {
            if (! array_key_exists('linked_visit_id', $result)) {
                return $result;
            }
            $visitId = $result['linked_visit_id'];
            unset($result['linked_visit_id']);

            return [...$result, 'linked_visit' => $visitId === null ? null : ($visits[$visitId] ?? null)];
        }, $results);
    }
}
