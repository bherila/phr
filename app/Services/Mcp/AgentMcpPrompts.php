<?php

namespace App\Services\Mcp;

final class AgentMcpPrompts
{
    /** @return array{user: string} */
    public function safelyUpdateClinicalRecord(): array
    {
        return ['user' => implode(' ', [
            'Help me safely update a clinical record in PHR.',
            'Call identity.get, patients.list, and patients.get; select only a patient ID returned by PHR and never infer an ID from names or prior conversations.',
            'Read the current patient-scoped record before writing. Confirm that the proposed normalized fields are supported by the source evidence and preserve source_document_id unless changing it is explicitly intended.',
            'Call the resource-specific update tool with the returned record ID and current opaque version. Do not change import_source or external_id.',
            'Explain that an effective change reopens pending human review, then report the returned record and review state without exposing unrelated patient data.',
        ])];
    }

    /** @return array{user: string} */
    public function reviewImportProposal(): array
    {
        return ['user' => implode(' ', [
            'Help me review a staged PHR import proposal without creating duplicate health records.',
            'Call identity.get, patients.list, and patients.get to establish the exact patient. Inspect the import and existing patient records before proposing changes.',
            'Separate source evidence from interpreted normalized data. For every proposed record, identify its stable provenance and deterministic external_id, then classify it as create, update, unchanged, conflict, or unsupported.',
            'Present the exact candidate summary and wait for explicit user approval before calling imports.review or another write tool. Keep accepted records pending_review unless the user explicitly approves the clinical facts.',
        ])];
    }
}
