<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Http\Request;
use LogicException;
use Mcp\Server\Session\SessionInterface;

/**
 * Which MCP tools a session lists. A small core is listed in every session;
 * every other tool belongs to exactly one named toolset, which a client
 * lists with toolsets.enable. Hiding a tool only saves context: scopes alone
 * decide what a connection may call, and an authorized tool answers a call
 * by name whether or not it is listed.
 *
 * Membership is one rule, {@see self::toolsetFor()}: a tool's resource (its
 * name before the first dot) names its toolset, so a new tool on an existing
 * resource lands in that resource's toolset without being listed here.
 */
final class AgentMcpToolsets
{
    public const string LIST = 'toolsets.list';

    public const string ENABLE = 'toolsets.enable';

    public const string DISABLE = 'toolsets.disable';

    /** `?toolsets=all` on the MCP endpoint lists every authorized tool. */
    public const string QUERY_PARAMETER = 'toolsets';

    public const string ALL = 'all';

    /** The enabled toolset names, kept in the token-namespaced MCP session. */
    public const string SESSION_KEY = 'phr_enabled_toolsets';

    /** Listed in every session, whatever is enabled. */
    public const array CORE = [
        'identity.get',
        'capabilities.get',
        'patients.list',
        'patients.get',
        'records.search',
        'timeline.list',
        'changes.list',
        'clinical.get',
        'clinical.list',
        self::LIST,
        self::ENABLE,
        self::DISABLE,
    ];

    /** Every toolset, in the order toolsets.list shows them, with its description. */
    public const array TOOLSETS = [
        'clinical_writes' => 'Write clinical records: upsert, update, resolve external IDs, retract or delete allergies, conditions, immunizations, lab results, procedures and vitals, and list evidence links.',
        'patients' => 'Create or update patient profiles.',
        'documents' => 'List, read and edit documents and notes, store markdown notes, upload files (signed or small uploads) and create download access.',
        'medications' => 'Medications, prescription fills and medication transitions, with NDC lookup and search and FDA drug labels.',
        'encounters' => 'Write office visits and hospital stays, read a stay, and link records to a visit.',
        'episodes' => 'Write episodes of care and link encounters to them.',
        'eobs' => 'Explanation-of-benefits claims and their lines: list, import, upsert, update and reconcile.',
        'imports' => 'Create, inspect, retry and review C-CDA, FHIR and EHI export imports.',
        'imaging' => 'DICOM imaging studies and series, and DICOM uploads.',
        'exports' => 'Request structured exports and native backups and create their download access.',
        'health_logs' => 'Health logs and their entries.',
        'respiratory' => 'Sinus Sentinel respiratory events.',
        'genai' => 'The AI Agent Inbox: its queue status, and claiming, renewing, completing or failing the GenAI requests in it.',
    ];

    /**
     * A tool's resource, the name before its first dot, names its toolset.
     * A writable clinical type not named here is a clinical write.
     */
    private const array RESOURCES = [
        'clinical' => 'clinical_writes',
        'evidence' => 'clinical_writes',
        'patients' => 'patients',
        'documents' => 'documents',
        'medications' => 'medications',
        'medication_fills' => 'medications',
        'medication_transitions' => 'medications',
        'ndc' => 'medications',
        'office_visits' => 'encounters',
        'episodes' => 'episodes',
        'eobs' => 'eobs',
        'eob_lines' => 'eobs',
        'reconciliations' => 'eobs',
        'imports' => 'imports',
        'dicom_studies' => 'imaging',
        'dicom_series' => 'imaging',
        'dicom_uploads' => 'imaging',
        'exports' => 'exports',
        'native_backups' => 'exports',
        'health_logs' => 'health_logs',
        'health_log_entries' => 'health_logs',
        'respiratory_events' => 'respiratory',
    ];

    /**
     * The toolset a tool belongs to, or null for a core tool. A tool on a
     * resource no rule names fails loudly: it needs a toolset decision.
     */
    public static function toolsetFor(string $tool): ?string
    {
        if (in_array($tool, self::CORE, true)) {
            return null;
        }
        // The GenAI mailbox tools are named without a resource prefix.
        if (! str_contains($tool, '.') && preg_match('/(^|_)genai(_|$)/', $tool) === 1) {
            return 'genai';
        }
        $resource = explode('.', $tool, 2)[0];
        if (isset(self::RESOURCES[$resource])) {
            return self::RESOURCES[$resource];
        }
        if (str_contains($tool, '.') && in_array(str_replace('_', '-', $resource), AgentClinicalResourceCatalog::writableIds(), true)) {
            return 'clinical_writes';
        }

        throw new LogicException("MCP tool [{$tool}] belongs to no toolset; map its resource in AgentMcpToolsets::RESOURCES.");
    }

    /**
     * Whether this connection lists every authorized tool: the `toolsets=all`
     * opt-out for clients that cannot refresh their tool list, or the
     * deployment-wide setting.
     */
    public static function listsEverything(Request $request): bool
    {
        return $request->query(self::QUERY_PARAMETER) === self::ALL
            || config('agent_api.mcp_tool_listing') === self::ALL;
    }

    /** @return list<string> the toolsets this session enabled, in toolsets.list order */
    public static function enabled(SessionInterface $session): array
    {
        $stored = $session->get(self::SESSION_KEY, []);
        $stored = is_array($stored) ? $stored : [];

        return array_values(array_filter(
            array_keys(self::TOOLSETS),
            static fn (string $name): bool => in_array($name, $stored, true),
        ));
    }

    /** @param list<string> $toolsets */
    public static function store(SessionInterface $session, array $toolsets): void
    {
        $session->set(self::SESSION_KEY, array_values(array_intersect(array_keys(self::TOOLSETS), $toolsets)));
    }
}
