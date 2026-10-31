<?php

namespace App\Services\PHR\Preload;

/**
 * The GET requests the PHR shell sends on first load of a view, exactly as the browser writes
 * them, so the page can carry their responses (see PhrPreloadBuilder). Only what is on screen:
 * the current page's list, the open record, and nothing for other sections.
 *
 * Mirrors the client: the URL model in resources/js/phr/shell/phrRoutes.ts and each page's
 * first-load request. A URL that drifts from the client only costs the round trip it was meant
 * to save, because the browser falls back to the network on a miss; tests/e2e asserts the
 * preloaded views make no first-load API request.
 */
final readonly class PhrPreloadPlan
{
    /** PHR_PAGE_IDS; PageController::PATIENT_PAGES serves the shell for exactly these. */
    private const array PAGES = [
        'review', 'summary', 'health-log', 'labs', 'vitals', 'office-visits', 'medications', 'conditions',
        'procedures', 'immunizations', 'allergies', 'documents', 'imaging', 'access',
    ];

    /** Details shown on their own full-width page, without the list (PHR_DETAIL_ROUTES). */
    private const array PAGE_DETAILS = ['document-viewer', 'imaging-study-detail', 'vitals-trend'];

    /** Each page's own record type (the detail at /{page}/{id}). */
    private const array PRIMARY_DETAILS = [
        'labs' => 'lab-panel-detail',
        'vitals' => 'vitals-reading-detail',
        'imaging' => 'imaging-study-detail',
        'office-visits' => 'office-visit-detail',
        'medications' => 'medication-detail',
        'conditions' => 'condition-detail',
        'procedures' => 'procedure-detail',
        'immunizations' => 'immunization-detail',
        'allergies' => 'allergy-detail',
        'documents' => 'document-viewer',
        'access' => 'access-grant-detail',
    ];

    /** Other record types a page hosts, at /{page}/{kind}/{id} (HOSTED_DETAILS). */
    private const array HOSTED_DETAILS = [
        'office-visits' => ['procedure' => 'procedure-detail', 'immunization' => 'immunization-detail'],
        'vitals' => ['trend' => 'vitals-trend'],
    ];

    /** A record a detail opens in its own place, at /{id}/{kind}/{childId} (CHILD_DETAILS). */
    private const array CHILD_DETAILS = [
        'office-visit-detail' => ['imaging' => 'imaging-study-detail'],
    ];

    /** @param  list<string>  $urls  path and query, in priority order */
    public function __construct(public array $urls = []) {}

    /** Top-level sections. The patient list already ships with every shell page (app-initial-data). */
    public static function forSection(string $section): self
    {
        return new self(match ($section) {
            // The selection cards' counts and the "needs your attention" list.
            'patients' => ['/api/phr/patients/overview'],
            'data-hub' => ['/api/phr/data-hub', '/api/phr/data-hub/deletions', '/api/phr/data-hub/native-restores'],
            // The default tab, AI Providers.
            'config' => ['/api/user/ai-prefs', '/api/user/ai-execution-mode'],
            // Imports chooses its patient in the browser, from the patient list.
            default => [],
        });
    }

    /**
     * A patient view, from the raw (still percent-encoded) path after /phr/patient/{id}, read as
     * parsePhrLocation reads it: split on "/", then each segment decoded on its own.
     */
    public static function forPatient(int $patientId, string $rawPath): self
    {
        $base = "/api/phr/patients/{$patientId}";
        $segments = array_values(array_filter(explode('/', $rawPath), static fn (string $segment): bool => $segment !== ''));
        $segments = array_map(rawurldecode(...), $segments);
        $page = array_shift($segments);
        if ($page === null || ! in_array($page, self::PAGES, true)) {
            $page = 'summary';
            $segments = [];
        }

        $detail = self::readDetail($segments, 0, self::HOSTED_DETAILS[$page] ?? [], self::PRIMARY_DETAILS[$page] ?? null);
        $parent = null;
        if ($detail !== null) {
            $child = self::readDetail($segments, $detail['next'], self::CHILD_DETAILS[$detail['module']] ?? [], null);
            if ($child !== null) {
                [$parent, $detail] = [$detail, $child];
            }
        }

        // An id the page would not request as written gets no detail entry; the page still opens.
        $detailUrls = $detail === null ? [] : self::detailUrls($base, $detail['module'], $detail['id']);
        $listOnScreen = $detail === null || $parent !== null || ! in_array($detail['module'], self::PAGE_DETAILS, true);

        // The sidebar, on every patient view, loads the patient's review counts.
        $sidebar = ["{$base}/review/summary"];

        return new self(array_values(array_unique([...$detailUrls, ...($listOnScreen ? self::listUrls($base, $page) : []), ...$sidebar])));
    }

    /**
     * Requests the client sends next from a preloaded body, without any user action: the Health
     * Log page opens its first log's entries.
     *
     * @return list<string>
     */
    public function followUps(string $url, mixed $body): array
    {
        if (preg_match('#^(/api/phr/patients/\d+/health-logs)$#', $url, $match) !== 1 || ! is_object($body)) {
            return [];
        }
        $logs = $body->health_logs ?? null;
        $first = is_array($logs) ? ($logs[0] ?? null) : null;
        $id = is_object($first) ? ($first->id ?? null) : null;

        return is_int($id) ? ["{$match[1]}/{$id}/entries"] : [];
    }

    /** @return list<string> */
    private static function listUrls(string $base, string $page): array
    {
        return match ($page) {
            'review' => ["{$base}/review"],
            'summary' => [$base, "{$base}/lab-results", "{$base}/vitals", "{$base}/dicom/studies", "{$base}/exports"],
            'health-log' => ["{$base}/health-logs"],
            'labs' => ["{$base}/lab-results"],
            'office-visits' => ["{$base}/office-visits", "{$base}/procedures", "{$base}/immunizations"],
            'medications', 'conditions', 'procedures', 'immunizations', 'allergies', 'vitals', 'documents' => ["{$base}/{$page}"],
            'imaging' => ["{$base}/dicom/studies", $base],
            'access' => [$base],
            default => [],
        };
    }

    /** @return list<string> */
    private static function detailUrls(string $base, string $module, string $id): array
    {
        if ($module === 'access-grant-detail') {
            return [$base];
        }
        if ($module === 'vitals-trend') {
            return preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1 ? ["{$base}/vitals/trend/{$id}"] : [];
        }
        if (preg_match('/^\d+$/', $id) !== 1) {
            return [];
        }

        return match ($module) {
            'lab-panel-detail' => ["{$base}/labs/{$id}"],
            'vitals-reading-detail' => ["{$base}/vitals/{$id}"],
            'imaging-study-detail' => ["{$base}/dicom/studies/{$id}", "{$base}/dicom/studies/{$id}/viewer-json"],
            'office-visit-detail' => ["{$base}/office-visits/{$id}"],
            'medication-detail' => ["{$base}/medications/{$id}"],
            'condition-detail' => ["{$base}/conditions/{$id}"],
            'procedure-detail' => ["{$base}/procedures/{$id}"],
            'immunization-detail' => ["{$base}/immunizations/{$id}"],
            'allergy-detail' => ["{$base}/allergies/{$id}"],
            'document-viewer' => ["{$base}/documents/{$id}"],
            default => [],
        };
    }

    /**
     * @param  list<string>  $segments
     * @param  array<string, string>  $named
     * @return array{module: string, id: string, next: int}|null
     */
    private static function readDetail(array $segments, int $at, array $named, ?string $primary): ?array
    {
        $head = $segments[$at] ?? null;
        if ($head === null) {
            return null;
        }
        if (isset($named[$head])) {
            $id = $segments[$at + 1] ?? null;

            return $id === null ? null : ['module' => $named[$head], 'id' => $id, 'next' => $at + 2];
        }

        return $primary === null ? null : ['module' => $primary, 'id' => $head, 'next' => $at + 1];
    }
}
