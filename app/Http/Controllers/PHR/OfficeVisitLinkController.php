<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Models\PhrOfficeVisit;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Encounters\PhrEncounterLinkBatch;
use App\Services\PHR\Encounters\PhrEncounterLinks;
use App\Services\PHR\Encounters\PhrLinkableRecords;
use App\Services\PHR\Encounters\PhrLinkSuggestions;
use App\Support\PHR\Validation\LinkSuggestionQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Attach existing records to a visit or stay from its page, and remove them (#449). Every
 * write goes through PhrEncounterLinks, so the rules and side effects are the agent's
 * `office_visits.link_record` / `unlink_record`: one standing visit per record, nothing
 * linked to or from a rejected record or visit, and no record's version, review status or
 * change-feed position moves. Only people who can edit the patient use these; a viewer
 * reads the links on the visit itself. The stay's suggestions (#450) are confirmed through
 * the same batch `links`.
 */
final class OfficeVisitLinkController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrEncounterLinks $links,
    ) {}

    public function linkable(Request $request, int $patient, int $visit, PhrLinkableRecords $records): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(PhrEncounterLinks::types())],
            'q' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'limit' => ['nullable', 'integer', 'between:1,'.PhrLinkableRecords::MAX_LIMIT],
        ]);
        $patientId = (int) $this->accessService->writablePatient($patient, (int) $request->user()?->id)->id;
        $resolved = PhrOfficeVisit::query()->where('patient_id', $patientId)->whereNull('retracted_at')->findOrFail($visit);

        return response()->json([
            'office_visit_id' => (int) $resolved->id,
            ...$records->page(
                $patientId,
                (int) $resolved->id,
                (string) $validated['type'],
                [
                    'q' => isset($validated['q']) ? (string) $validated['q'] : null,
                    'from' => isset($validated['from']) ? (string) $validated['from'] : null,
                    'to' => isset($validated['to']) ? (string) $validated['to'] : null,
                ],
                (int) ($validated['limit'] ?? PhrLinkableRecords::DEFAULT_LIMIT),
                isset($validated['cursor']) ? (string) $validated['cursor'] : null,
            ),
        ]);
    }

    /**
     * The records dated inside the stay that no visit holds yet, and those another visit
     * holds, for the visit page's "Suggested from these dates" panel (#450). Nothing is
     * linked until the person confirms through `links`.
     */
    public function suggestions(Request $request, int $patient, int $visit, PhrLinkSuggestions $suggestions): JsonResponse
    {
        $validated = LinkSuggestionQuery::validate($request);
        $patientId = (int) $this->accessService->writablePatient($patient, (int) $request->user()?->id)->id;
        $resolved = PhrOfficeVisit::query()->where('patient_id', $patientId)->whereNull('retracted_at')->findOrFail($visit);

        return response()->json([
            'office_visit_id' => (int) $resolved->id,
            ...$suggestions->suggest(
                $patientId,
                $resolved,
                $validated['types'] ?? PhrEncounterLinks::types(),
                $validated['margin_days'],
                $validated['limit'],
                $validated['list'],
                $validated['cursor'],
            ),
        ]);
    }

    public function link(Request $request, int $patient, int $visit, PhrEncounterLinkBatch $batch): JsonResponse
    {
        $validated = $request->validate([
            'links' => ['required', 'array', 'min:1', 'max:'.PhrEncounterLinkBatch::MAX_LINKS],
            'links.*' => ['required', 'array:type,id'],
            'links.*.type' => ['required', 'string', Rule::in(PhrEncounterLinks::types())],
            'links.*.id' => ['required', 'integer', 'min:1'],
        ]);
        $patientId = (int) $this->accessService->writablePatient($patient, (int) $request->user()?->id)->id;
        $items = array_values(array_map(
            static fn (array $link): array => ['type' => (string) $link['type'], 'id' => (int) $link['id']],
            (array) $validated['links'],
        ));
        $results = $batch->link($patientId, $visit, $items);
        $count = static fn (string $outcome): int => count(array_filter($results, static fn (array $result): bool => $result['outcome'] === $outcome));

        return response()->json([
            'office_visit_id' => $visit,
            'results' => $results,
            'summary' => [
                PhrEncounterLinks::LINKED => $count(PhrEncounterLinks::LINKED),
                PhrEncounterLinks::UNCHANGED => $count(PhrEncounterLinks::UNCHANGED),
                PhrEncounterLinkBatch::REFUSED => $count(PhrEncounterLinkBatch::REFUSED),
            ],
        ]);
    }

    public function unlink(Request $request, int $patient, int $visit, string $type, int $record): JsonResponse
    {
        abort_unless(in_array($type, PhrEncounterLinks::types(), true), 404);
        $patientId = (int) $this->accessService->writablePatient($patient, (int) $request->user()?->id)->id;
        $outcome = $this->links->unlink($patientId, $visit, $type, $record);

        return response()->json([
            'office_visit_id' => $visit,
            'type' => $type,
            'id' => $record,
            'outcome' => $outcome,
        ]);
    }
}
