<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiPrincipal;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentOperations;
use App\Support\Logging\McpSafeLogger;
use Bherila\GenAiLaravel\Mcp\GenAiMcpToolCatalog;
use Bherila\GenAiLaravel\Mcp\Tools\GenAiMcpTools;
use Bherila\McpLaravelBridge\Mcp\CredentialSessionNamespace;
use Bherila\McpLaravelBridge\Mcp\OriginalShapeSchemaValidator;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;
use Bherila\McpLaravelBridge\Mcp\ValidatedCallToolHandler;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Log\NullLogger;

final class AgentMcpServerFactory
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly AgentMcpToolCatalog $catalog,
        private readonly AgentMcpReadTools $reads,
        private readonly AgentMcpWriteTools $writes,
        private readonly AgentMcpPrompts $prompts,
        private readonly AgentMcpInputSchemaFactory $schemas,
        private readonly AgentMcpOutputSchemaFactory $outputSchemas,
        private readonly RequestArguments $requestArguments,
        private readonly GenAiMcpToolCatalog $genAiCatalog,
        private readonly GenAiMcpTools $genAiTools,
        private readonly AgentOperations $operations,
    ) {}

    public function make(Request $request): Server
    {
        return $this->prepare($request)['builder']->build();
    }

    public function discoveryConfiguration(Request $request): Configuration
    {
        return $this->prepare($request)['configuration'];
    }

    /** @return array{builder: Builder, configuration: Configuration} */
    private function prepare(Request $request): array
    {
        $logger = new NullLogger;
        $driftLogger = new McpSafeLogger;
        $registry = new Registry(logger: $logger);
        $referenceHandler = new ReferenceHandler(app());
        $phrDefinitions = $this->catalog->definitions($this->reads, $this->writes);
        $genAiDefinitions = $this->genAiCatalog->definitions($this->genAiTools);
        $genAiToolNames = array_fill_keys(array_map(
            static fn (ToolDefinition $definition): string => $definition->name,
            $genAiDefinitions,
        ), true);
        $definitions = [...$phrDefinitions, ...$genAiDefinitions];
        // One availability evaluation decides every tool, PHR and GenAI alike.
        $available = $this->operations->availability()->evaluate(new AgentApiPrincipal($request));
        $exposedDefinitions = array_values(array_filter(
            $definitions,
            static fn (ToolDefinition $definition): bool => $available->isAvailable($definition->name),
        ));
        $exposedToolNames = array_fill_keys(array_map(
            static fn (ToolDefinition $definition): string => $definition->name,
            $exposedDefinitions,
        ), true);
        $schemaIds = [];
        foreach ($exposedDefinitions as $definition) {
            $schemaIds[$definition->name] = isset($genAiToolNames[$definition->name])
                ? 'genai-mcp.v1.'.$definition->name
                : AgentApiResponseSchemaCatalog::operationComponent($definition->responseOperationId());
        }
        $serverInfo = new Implementation(
            name: 'PHR Agent API',
            version: 'v1',
            description: 'Read and safely update authorized personal health records through the versioned PHR REST API.',
            websiteUrl: url('/'),
        );
        $instructions = $this->instructions($exposedToolNames);
        $builder = Server::builder()
            ->setServerInfo($serverInfo->name, $serverInfo->version, $serverInfo->description, websiteUrl: $serverInfo->websiteUrl)
            ->setInstructions($instructions)
            // This endpoint exposes discovery for the initialize lifecycle.
            ->withoutModernEra();
        $protocolVersion = ProtocolVersion::tryFrom((string) $request->header('Mcp-Protocol-Version', ''));
        if ($protocolVersion !== null) {
            // An explicit transport revision must mean the same thing to both
            // discovery methods, even when initialize offers another revision.
            $builder->setProtocolVersion($protocolVersion);
        }

        $hasClinicalPrompt = $this->hasTools($exposedToolNames, [
            'identity.get',
            'patients.list',
            'patients.get',
            'records.search',
            'office_visits.update',
        ]);
        if ($hasClinicalPrompt) {
            $builder->addPrompt(
                handler: [$this->prompts, 'safelyUpdateClinicalRecord'],
                name: 'safely-update-clinical-record',
                title: 'Safely update a clinical record',
                description: 'Discover the exact patient and current record before a version-checked clinical update.',
            );
        }
        $hasImportPrompt = $this->hasTools($exposedToolNames, [
            'identity.get',
            'patients.list',
            'patients.get',
            'records.search',
            'imports.list',
            'imports.get',
            'imports.review',
        ]);
        if ($hasImportPrompt) {
            $builder->addPrompt(
                handler: [$this->prompts, 'reviewImportProposal'],
                name: 'review-import-proposal',
                title: 'Review an import proposal',
                description: 'Inventory evidence and existing records, prevent duplicates, and wait for approval before import writes.',
            );
        }

        $builder->setPaginationLimit(100)
            // Sessions contain protocol negotiation state, never tool arguments or
            // results. A token-derived cache namespace prevents possession of a
            // session UUID from crossing OAuth-token boundaries.
            ->setSession(new Psr16SessionStore(
                cache: $this->cache,
                prefix: CredentialSessionNamespace::prefix($request, 'phr_mcp_'),
                ttl: (int) config('agent_api.mcp_session_ttl_seconds', 1800),
            ))
            // The SDK's debug logger includes tool arguments and results. A null
            // logger is mandatory here because both can contain health records.
            ->setLogger($logger)
            ->setContainer(app())
            ->setRegistry($registry)
            ->setReferenceHandler($referenceHandler)
            // Register first so the protocol selects the shape-aware validator
            // before the SDK's default CallToolHandler.
            ->addRequestHandler(new ValidatedCallToolHandler(
                new CallToolHandler(
                    $registry,
                    $referenceHandler,
                    $logger,
                    new OriginalShapeSchemaValidator($logger, $this->requestArguments),
                ),
                $registry,
                // A plain validator: the shape-aware subclass consumes the queued
                // wire arguments, which belong to input validation only. Null
                // logger because the SDK logs data and schema on internal errors.
                new SchemaValidator($logger),
                $schemaIds,
                $driftLogger,
                'The PHR API returned a response that failed its output contract.',
            ))
            ->setLazyLoading(false);

        foreach ($exposedDefinitions as $definition) {
            $builder->addTool(
                handler: $definition->handler,
                name: $definition->name,
                title: $definition->title,
                description: $definition->description,
                annotations: new ToolAnnotations(
                    readOnlyHint: $definition->readOnly,
                    destructiveHint: $definition->destructive,
                    idempotentHint: $definition->idempotent,
                    openWorldHint: false,
                ),
                inputSchema: $this->schemas->for($definition),
                outputSchema: isset($genAiToolNames[$definition->name])
                    ? AgentMcpOutputSchemaFactory::withoutDescriptions($this->genAiCatalog->outputSchema($definition->name))
                    : $this->outputSchemas->for($definition),
            );
        }

        // Share the exact metadata between initialize and sessionless discovery.
        $capabilities = new ServerCapabilities(
            tools: $exposedDefinitions !== [],
            resources: false,
            prompts: $hasClinicalPrompt || $hasImportPrompt,
            logging: true,
            completions: true,
        );
        $builder->setCapabilities($capabilities);

        return [
            'builder' => $builder,
            'configuration' => new Configuration($serverInfo, $capabilities, 100, $instructions, $protocolVersion),
        ];
    }

    /**
     * @param  array<string, true>  $available
     * @param  list<string>  $required
     */
    private function hasTools(array $available, array $required): bool
    {
        return array_diff($required, array_keys($available)) === [];
    }

    /** @param array<string, true> $available */
    private function instructions(array $available): string
    {
        $hasPatientContext = $this->hasTools($available, ['identity.get', 'patients.list', 'patients.get']);
        $hasClinicalUpdate = $this->hasTools($available, ['records.search', 'office_visits.update']);
        $hasImportReview = $this->hasTools($available, ['records.search', 'imports.list', 'imports.get', 'imports.review']);

        if ($hasPatientContext && $hasClinicalUpdate) {
            $base = 'First call identity.get, then patients.list; select only a patient ID returned by PHR, never guess one, and confirm it with patients.get before reading or writing. Read existing patient-scoped records before every write. Prevent duplicates: every clinical upsert needs stable provenance and a deterministic external_id. Before an update, read the target record and supply its returned ID and current opaque version. Keep changes pending_review unless the user explicitly approves the clinical facts.';
        } elseif ($hasPatientContext && $hasImportReview) {
            $base = 'First call identity.get, then patients.list; select only a patient ID returned by PHR, never guess one, and confirm it with patients.get before reviewing an import. Inspect the staged proposal and existing patient-scoped records, preserve stable provenance and deterministic external_id values, and obtain explicit user approval before accepting or rejecting a proposal.';
        } elseif ($hasPatientContext) {
            $base = 'First call identity.get, then patients.list; select only a patient ID returned by PHR, never guess one, and confirm it with patients.get. Use only operations currently exposed in tools/list; missing tools are not authorized for this connection.';
        } else {
            $base = 'Use only operations currently exposed in tools/list; missing tools are not authorized for this connection. Never guess a patient or record ID.';
        }

        $details = [];
        if ($hasClinicalUpdate) {
            $details[] = 'The resource-specific update tools require clinical:read and clinical:write, preserve import identity unless explicitly changed, conflict if the record changed, and reopen human review on every effective change.';
        }
        if ($hasImportReview) {
            $details[] = 'Keep interpreted source data distinct from evidence, inspect staged proposals and existing records, and obtain explicit user approval before imports.review.';
        }
        if (isset($available['imports.create'])) {
            $details[] = 'imports.create reads a C-CDA, FHIR, or MyChart or EHI export document with PHR\'s own parsers, not AI: its records land pending review, and an EHI export is reviewed by the patient\'s owner in PHR. Any other document is not queued for AI: PHR runs no AI for a connected client, so you extract it yourself, and imports_create answers client_extraction_required with the write tools that apply (medication_fills_upsert, eobs_upsert, medications_upsert and the other upserts), each taking the document as source_document_id.';
        }
        $signedUpload = $this->hasTools($available, ['documents.upload_access_create', 'documents.upload_finalize']);
        if ($signedUpload) {
            $details[] = 'To get a file into PHR, prefer a signed upload for photos and PDFs: documents_upload_access_create (kind eob, pharmacy_receipt, rx_label, statement or other; filename; content_type; the exact byte_size; sha256 when you have it), then PUT the raw file to the returned url with the returned headers and no Authorization header (the URL expires in minutes), then documents_upload_finalize, which verifies size and hash and returns document_id. Use documents_upload only for small files. PHR runs no AI on documents for you: you read every label, receipt, statement and every EOB that eobs_import reports as unrecognised_format yourself, and write what it shows with the upserts, always reading the patient\'s existing records first.';
        }
        if ($this->hasTools($available, ['ndc.lookup', 'medication_fills.list', 'medication_fills.upsert'])) {
            $upload = $signedUpload ? 'upload the source (signed upload, as above)' : 'upload the source with documents_upload first';
            $details[] = "To import prescription fills from a label photo, receipt PDF, pharmacy statement or CSV: {$upload}; identify the patient from the name printed on it with patients_list (name, and birth_date when printed), and if no patient or several match, ask the user, never guess and never create a patient; read the NDC and call ndc_lookup, or ndc_search by drug name, strength, dosage form, route, labeler and package size when the NDC is missing or unreadable, and never choose among several candidates yourself, ask the user; call medication_fills_list for the patient before writing; then medication_fills_upsert with the NDC or the chosen ndc_candidate, the fill, price, pharmacy and provenance (source_kind, source_document_id, source_locator), one fill per receipt or statement line. Never send drug fields: the server resolves them from the NDC, links or creates the medication pending review; the fill is the source of truth, so do not also write a medication for a line that yields a fill. A label, a receipt and an EOB line of the same fill are one fill: the server matches on external_id (derived from pharmacy, Rx number and fill date) and otherwise on fill date, drug, pharmacy and quantity, adds the new source as evidence, and flags an uncertain match (review_flags possible_duplicate) for the person instead of duplicating it; send what each source shows and do not try to merge fills yourself. The person decides flagged fills in PHR's Review page; call medication_fills_resolve_duplicate (same or different, with the flagged fill's current version) only when the person has told you which it is.";
        }
        if ($this->hasTools($available, ['eobs.list', 'eobs.import', 'eobs.upsert'])) {
            $details[] = 'To import an EOB (explanation of benefits): upload it (signed upload, as above), then eobs_import with the document_id. It reads Meritain EOB PDFs and Delta Dental claim CSVs with PHR\'s own parsers, idempotently, and the claim lands pending review. If it answers unrecognised_format, read the document yourself, call eobs_list for the patient (read first), then eobs_upsert with the claim, its lines and source_document_id; it is idempotent on the claim fingerprint across every source, so a claim PHR already holds comes back unchanged. For a pharmacy claim, give each line its fill (ndc or drug_as_printed, quantity, rx_number, pharmacy_name, filled_on) in eobs_upsert, or after eobs_import list the lines (eob_lines_list) and call medication_fills_upsert with each line as eob_line_id: either way the fill is linked to the EOB line that adjudicated it and merged with the label or receipt of the same fill. Correct your own claim with eobs_update and its current version.';
        }
        if (isset($available['documents.download_access.create'])) {
            $details[] = 'Request document download access only when file contents are explicitly needed.';
        }

        $promptGuidance = [];
        if ($hasPatientContext && $hasClinicalUpdate) {
            $promptGuidance[] = 'safely-update-clinical-record';
        }
        if ($hasPatientContext && $hasImportReview) {
            $promptGuidance[] = 'review-import-proposal';
        }
        if ($promptGuidance !== []) {
            $details[] = 'Use the '.implode(' and ', $promptGuidance).' prompts for complete guided workflows when the client exposes MCP prompts.';
        }

        $details[] = 'Authenticate with OAuth Authorization Code plus S256 PKCE and request only the narrow identity:read, patients:read, clinical, document, or import scopes needed for the task.';

        $hasGenAiStatus = isset($available['genai_queue_status']);
        $hasGenAiWork = isset($available['claim_genai_request']);
        if ($hasGenAiWork) {
            // In PHR this queue is the person's AI Agent Inbox: the documents they
            // send to AI from PHR wait in it for their own agent to process.
            $details[] = 'In PHR this queue is the AI Agent Inbox: documents the person sends to AI from PHR wait here for you to process.';
            $details[] = $this->genAiCatalog->instructions();
        } elseif ($hasGenAiStatus) {
            $details[] = 'You may inspect the private external GenAI queue status, but this connection was not granted permission to claim or complete work.';
        }

        return $base.' '.implode(' ', $details);
    }
}
