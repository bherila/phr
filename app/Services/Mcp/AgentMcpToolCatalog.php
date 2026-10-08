<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiPrincipal;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Bherila\McpLaravelBridge\Capabilities\McpBinding;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\OperationToolFactory;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Bherila\McpLaravelBridge\Capabilities\WriteSafety;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;
use Closure;
use LogicException;

/**
 * The versioned REST operations exposed through MCP, declared once as
 * capability-registry operations. Each tool's scopes are those of the REST
 * operation whose response it returns, read from the shipped OpenAPI
 * document, so the document stays the one source of permissions.
 */
final class AgentMcpToolCatalog
{
    /** @return list<ToolDefinition> */
    public function definitions(AgentMcpReadTools $reads, AgentMcpWriteTools $writes): array
    {
        $tools = new OperationToolFactory;

        return array_map($tools->definition(...), $this->operations($reads, $writes));
    }

    /** @return list<Operation> */
    public function operations(AgentMcpReadTools $reads, AgentMcpWriteTools $writes): array
    {
        $byExternalId = new WriteSafety(idempotencyKey: IdempotencyKey::Argument);
        $byVersion = new WriteSafety(expectedVersion: true);

        $operations = [
            $this->read('capabilities.get', 'Get capabilities', 'Discover the PHR API version, scopes, operations, resources, and limits.', [$reads, 'capabilitiesGet']),
            $this->read('identity.get', 'Get identity', 'Return the authorized account identity and granted OAuth scopes.', [$reads, 'identityGet']),
            $this->read('patients.list', 'List patients', 'List only patients accessible to the authorized account, with bounded pagination.', [$reads, 'patientsList']),
            $this->read('patients.get', 'Get patient', 'Get one accessible patient and its current access metadata.', [$reads, 'patientsGet']),
            // Deliberately not idempotent. Patient creation carries no
            // client-supplied external ID to deduplicate on, so a repeated call
            // writes a second profile for the same person, and unlike a
            // duplicated export row or upload session that duplicate is
            // permanent: a patient is the identity everything else is scoped by
            // and nothing in the application merges two profiles. Advertising
            // idempotentHint: true would invite a harness to retry a timed-out
            // call and silently split a person's longitudinal record.
            $this->write(
                'patients.create',
                'Create patient',
                'Create a new PHR patient owned by the authorized account and grant that account owner-level access to it. This is not idempotent and has no deduplication key: confirm with patients.list that the person is not already present before calling it, and never retry a call whose outcome is unknown without checking first.',
                [$writes, 'patientsCreate'],
                new WriteSafety(note: 'No deduplication key: confirm the person is absent with patients.list first.'),
                idempotent: false,
            ),
            $this->read('records.search', 'Search records', 'Search clinical records using the versioned REST filters and cursor pagination.', [$reads, 'recordsSearch']),
            $this->read('timeline.list', 'List timeline', 'List a patient timeline using the versioned REST filters and cursor pagination.', [$reads, 'timelineList']),
            $this->read('changes.list', 'List changes', 'List bounded clinical change states for one patient. Reuse the returned watermark with every cursor page; deleted and retracted records are tombstones.', [$reads, 'changesList']),
            $this->read('eobs.list', 'List EOBs', 'List explanation-of-benefits records for an accessible patient.', [$reads, 'eobsList']),
            $this->read('eobs.get', 'Get EOB', 'Get one explanation-of-benefits record for an accessible patient.', [$reads, 'eobsGet']),
            $this->read('eob_lines.list', 'List EOB lines', 'List line items for one accessible explanation-of-benefits record.', [$reads, 'eobLinesList']),
            $this->read('eob_lines.get', 'Get EOB line', 'Get one line item from an accessible explanation-of-benefits record.', [$reads, 'eobLinesGet']),
            $this->read('evidence.links', 'List evidence links', 'List typed links between a clinical record and its supporting evidence.', [$reads, 'evidenceLinks']),
            $this->read('documents.list', 'List documents', 'List document metadata without returning file contents.', [$reads, 'documentsList']),
            $this->read('documents.get', 'Get document', 'Get document metadata without returning file contents.', [$reads, 'documentsGet']),
            $this->read('documents.download_access.create', 'Create document download access', 'Create short-lived, OAuth-bound download access for one authorized document.', [$reads, 'documentsDownloadAccessCreate']),
            $this->read('dicom_studies.list', 'List imaging studies', 'List bounded DICOM study metadata without returning image pixels or instance files.', [$reads, 'dicomStudiesList']),
            $this->read('dicom_studies.get', 'Get imaging study', 'Get one DICOM study metadata record without returning image pixels or instance files.', [$reads, 'dicomStudiesGet']),
            $this->read('dicom_series.list', 'List imaging series', 'List bounded series metadata for one DICOM study without returning image pixels or instance metadata.', [$reads, 'dicomSeriesList']),
            $this->write(
                'dicom_uploads.open',
                'Open DICOM upload',
                'Open a DICOM upload session. Use dicom_uploads.upload_file only for small instances; upload larger instances through the multipart REST endpoint, then finalize.',
                [$writes, 'dicomUploadsOpen'],
                new WriteSafety(note: 'Opens a session that is finalized or cancelled explicitly.'),
            ),
            $this->write(
                'dicom_uploads.upload_file',
                'Upload small DICOM file',
                'Upload one small DICOM instance to an open session. For larger instances use the multipart REST endpoint; never put raw imaging in a chat response.',
                [$writes, 'dicomUploadsUploadFile'],
                new WriteSafety(note: 'Stages a file inside one open session; nothing is visible until it is finalized.'),
                effect: Effect::Upload,
            ),
            $this->write(
                'dicom_uploads.finalize',
                'Finalize DICOM upload',
                'Finalize an open DICOM upload session after every intended instance has been uploaded.',
                [$writes, 'dicomUploadsFinalize'],
                new WriteSafety(note: 'Acts once on one open session; a closed session refuses it.'),
                effect: Effect::Destructive,
            ),
            $this->write(
                'dicom_uploads.cancel',
                'Cancel DICOM upload',
                'Cancel an open DICOM upload session and reclaim its stored objects.',
                [$writes, 'dicomUploadsCancel'],
                new WriteSafety(note: 'Acts once on one open session; a closed session refuses it.'),
                effect: Effect::Destructive,
            ),
            $this->read('reconciliations.preview', 'Preview reconciliation', 'Produce a count-only, dry-run EOB reconciliation plan and its confirmation digest.', [$reads, 'reconciliationsPreview']),
            $this->write(
                'reconciliations.apply',
                'Apply reconciliation',
                'Apply exactly the reconciliation preview identified by its digest. Request a new preview if this reports a conflict.',
                [$writes, 'reconciliationsApply'],
                new WriteSafety(confirm: true, note: 'Applies only the preview whose digest it carries.'),
                effect: Effect::Destructive,
                requiresOperations: ['reconciliations.preview'],
            ),
            $this->read('exports.list', 'List exports', 'List bounded asynchronous export status for an owned patient.', [$reads, 'exportsList']),
            $this->read('exports.download_access.create', 'Create export download access', 'Create short-lived, OAuth-bound download access for one ready export.', [$reads, 'exportsDownloadAccessCreate']),
            $this->read('native_backups.list', 'List native backups', 'List bounded asynchronous native-backup status for an owned patient.', [$reads, 'nativeBackupsList']),
            $this->read('native_backups.download_access.create', 'Create native-backup download access', 'Create short-lived, OAuth-bound download access for one ready native backup.', [$reads, 'nativeBackupsDownloadAccessCreate']),
            $this->write('exports.create', 'Request export', 'Queue a structured export. Poll exports.list until it is ready, then explicitly request download access.', [$writes, 'exportsCreate'], new WriteSafety(note: 'Queues a job; a repeat queues another.')),
            $this->write('native_backups.create', 'Request native backup', 'Queue a complete native backup. Poll native_backups.list until it is ready, then explicitly request download access.', [$writes, 'nativeBackupsCreate'], new WriteSafety(note: 'Queues a job; a repeat queues another.')),
            $this->write(
                'documents.upload',
                'Upload document',
                'Idempotently upload a small document through the versioned REST API. Use the multipart REST endpoint for larger files.',
                [$writes, 'documentsUpload'],
                $byExternalId,
                effect: Effect::Upload,
            ),
            $this->read('imports.list', 'List imports', 'List bounded import-job status for an accessible patient.', [$reads, 'importsList']),
            $this->read('imports.get', 'Get import', 'Inspect one import job and its proposed structured records.', [$reads, 'importsGet']),
            $this->write(
                'imports.create',
                'Create import',
                'Idempotently enqueue structured extraction for a stored patient document.',
                [$writes, 'importsCreate'],
                new WriteSafety(note: 'One import per stored document; a repeat returns it.'),
            ),
            $this->write(
                'imports.review',
                'Review import proposal',
                'Accept or reject one proposed record through the versioned REST workflow.',
                [$writes, 'importsReview'],
                new WriteSafety(note: 'Decides one proposal; a decided proposal refuses another decision.'),
                effect: Effect::Destructive,
            ),
            $this->write(
                'imports.retry',
                'Retry import',
                'Safely retry a failed import job that has retry capacity.',
                [$writes, 'importsRetry'],
                new WriteSafety(note: 'Only a failed import with retry capacity is retried.'),
                effect: Effect::Destructive,
            ),
            $this->read('health_log_entries.list', 'List health log entries', 'List bounded entries for one accessible health log.', [$reads, 'healthLogEntriesList']),
            $this->read('health_log_entries.get', 'Get health log entry', 'Get one entry from an accessible health log.', [$reads, 'healthLogEntriesGet']),
            $this->write(
                'health_logs.create',
                'Create health log',
                'Idempotently create a patient health log through the versioned REST API.',
                [$writes, 'healthLogsCreate'],
                $byExternalId,
            ),
            $this->write(
                'health_log_entries.append',
                'Append health log entry',
                'Idempotently append an entry to a patient health log.',
                [$writes, 'healthLogEntriesAppend'],
                $byExternalId,
            ),
            $this->read('respiratory_events.list', 'List respiratory events', 'List bounded Sinus Sentinel events for an accessible patient.', [$reads, 'respiratoryEventsList']),
            $this->write(
                'respiratory_events.ingest',
                'Ingest respiratory events',
                'Idempotently ingest a bounded Sinus Sentinel event batch using the device validation contract.',
                [$writes, 'respiratoryEventsIngest'],
                new WriteSafety(note: 'Events are deduplicated by their device identifiers.'),
            ),
        ];

        foreach (AgentClinicalResourceCatalog::ids() as $resource) {
            $toolName = str_replace('-', '_', $resource);
            $title = ucwords(str_replace('-', ' ', $resource));
            $operations[] = $this->read(
                "{$toolName}.list",
                "List {$title}",
                "List {$title} for an accessible patient through the versioned REST API.",
                $reads->clinicalListHandler($resource),
                respondsAs: 'clinical.list',
            );
            $operations[] = $this->read(
                "{$toolName}.get",
                "Get {$title}",
                "Get one {$title} record for an accessible patient through the versioned REST API.",
                $reads->clinicalGetHandler($resource),
                respondsAs: 'clinical.get',
            );
        }

        foreach (AgentClinicalResourceCatalog::writableIds() as $resource) {
            $title = ucwords(str_replace('-', ' ', $resource));
            $operations[] = $this->write(
                AgentClinicalResourceCatalog::upsertOperationId($resource),
                "Upsert {$title}",
                "Idempotently create or update one {$title} record through the versioned REST API.",
                $writes->clinicalUpsertHandler($resource),
                new WriteSafety(idempotencyKey: IdempotencyKey::Argument, expectedVersion: true),
                effect: Effect::Destructive,
            );
            $operations[] = $this->read(
                AgentClinicalResourceCatalog::mcpResolveToolId($resource),
                "Resolve {$title}",
                "Map a bounded batch of this connection's own external IDs onto {$title} record IDs and current versions. Returns no clinical content, so use it to decide what still needs writing before calling upsert.",
                $reads->clinicalResolveHandler($resource),
                respondsAs: AgentClinicalResourceCatalog::RESOLVE_OPERATION_ID,
            );
            $operations[] = $this->write(
                AgentClinicalResourceCatalog::mcpRetractToolId($resource),
                "Retract {$title}",
                "Withdraw one {$title} record this connection wrote, by its record ID and current version. Use this when the source is taking back a claim it made in error. A record simply missing from a newer export is NOT a reason to retract: institutions age data out past their own retention policies, so absence from a later import says nothing about whether the record was correct. Nothing is deleted and the external ID stays reserved.",
                $writes->clinicalRetractHandler($resource),
                $byVersion,
                effect: Effect::Destructive,
                respondsAs: AgentClinicalResourceCatalog::RETRACT_OPERATION_ID,
            );
            $operations[] = $this->write(
                AgentClinicalResourceCatalog::mcpUpdateToolId($resource),
                "Update {$title}",
                "Partially update one existing {$title} record by its patient-scoped record ID and current version. This preserves its import identity unless an explicit field is supplied.",
                $writes->clinicalUpdateHandler($resource),
                $byVersion,
                effect: Effect::Destructive,
                respondsAs: AgentClinicalResourceCatalog::UPDATE_OPERATION_ID,
            );
        }

        return $operations;
    }

    /**
     * What the OpenAPI document requires of an operation: nothing for one with
     * no security (the capabilities document), any credential for one secured
     * by OAuth without a scope (self-revocation), otherwise its scopes.
     */
    public static function requirementFor(string $operationId): Requirement
    {
        $declared = AgentApiResponseSchemaCatalog::operations()[$operationId] ?? throw new LogicException("Operation [{$operationId}] is not in the OpenAPI document.");

        return match (true) {
            $declared['scopes'] === null => Requirement::publicAccess(),
            $declared['scopes'] === [] => new Requirement(permissions: [AgentApiPrincipal::AUTHENTICATED]),
            default => new Requirement($declared['scopes']),
        };
    }

    /** @param  array{0: object, 1: string}|Closure  $handler */
    private function read(string $id, string $title, string $description, array|Closure $handler, ?string $respondsAs = null): Operation
    {
        return new Operation(
            id: $id,
            title: $title,
            description: $description,
            effect: Effect::Read,
            requirement: self::requirementFor($respondsAs ?? $id),
            mcp: new McpBinding(handler: $handler),
            output: $respondsAs === null ? null : SchemaRef::responseOf($respondsAs),
        );
    }

    /**
     * @param  array{0: object, 1: string}|Closure  $handler
     * @param  list<string>  $requiresOperations
     */
    private function write(
        string $id,
        string $title,
        string $description,
        array|Closure $handler,
        WriteSafety $safety,
        Effect $effect = Effect::LocalWrite,
        bool $idempotent = true,
        ?string $respondsAs = null,
        array $requiresOperations = [],
    ): Operation {
        return new Operation(
            id: $id,
            title: $title,
            description: $description,
            effect: $effect,
            requirement: self::requirementFor($respondsAs ?? $id),
            idempotent: $idempotent,
            safety: $safety,
            mcp: new McpBinding(handler: $handler),
            output: $respondsAs === null ? null : SchemaRef::responseOf($respondsAs),
            requiresOperations: $requiresOperations,
        );
    }
}
