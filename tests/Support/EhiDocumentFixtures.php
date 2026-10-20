<?php

namespace Tests\Support;

use App\Models\PhrDocument;
use App\Models\PhrEhiBlob;
use App\Models\PhrEhiEntity;
use App\Models\PhrEhiOccurrence;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiProposalRevision;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrEhiSource;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiMemberKind;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyOutcome;
use App\Services\PHR\Import\Ehi\Mapping\EhiCanonicalJson;
use App\Services\PHR\Import\Ehi\Mapping\EhiDocumentMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiProposalDraft;
use App\Services\PHR\Import\Ehi\Mapping\EhiTargetDigest;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Seeds projected EHI evidence for the document mapping directly (entities,
 * revisions, occurrences, note/media members with blobs) for one bound,
 * synthetic package, and persists drafts the way the proposal service would.
 * Every value is invented.
 */
trait EhiDocumentFixtures
{
    protected const string DOC_PATIENT_KEY = 'Z00094567';

    protected const string DOC_OTHER_PATIENT_KEY = 'Z00011112';

    protected const string DOC_MARKER = 'SYNTHETIC-SECRET-456';

    protected User $docOwner;

    protected PhrPatient $docPatient;

    protected PhrEhiSource $docSource;

    protected PhrEhiPackage $docPackage;

    /** @var array<string, PhrEhiPackageMember> "{package}:{table}" => table member */
    private array $docTableMembers = [];

    private int $docLine = 0;

    protected function setUpEhiDocuments(): void
    {
        Storage::fake(PhrDocument::STORAGE_DISK);
        $this->docOwner = $this->createUser();
        $this->docPatient = PhrPatient::query()->create([
            'owner_user_id' => $this->docOwner->id,
            'display_name' => 'Synthetic Patient',
            'relationship' => 'self',
        ]);
        $this->docSource = PhrEhiSource::query()->create([
            'patient_id' => $this->docPatient->id,
            'created_by_user_id' => $this->docOwner->id,
            'label' => 'Synthetic Clinic',
            'source_patient_key' => self::DOC_PATIENT_KEY,
        ]);
        $this->docPackage = $this->ehiDocPackage();
    }

    protected function ehiDocPackage(): PhrEhiPackage
    {
        return PhrEhiPackage::query()->create([
            'patient_id' => $this->docPatient->id,
            'source_id' => $this->docSource->id,
            'created_by_user_id' => $this->docOwner->id,
            'bound_by_user_id' => $this->docOwner->id,
            'manifest_digest' => hash('sha256', 'synthetic-package-'.PhrEhiPackage::query()->count()),
            'intake_kind' => PhrEhiPackage::INTAKE_DIRECTORY,
            'preservation_status' => PhrEhiPackage::PRESERVATION_PRESERVED,
            'accounting_status' => PhrEhiPackage::ACCOUNTING_ACCOUNTED,
            'binding_status' => PhrEhiPackage::BINDING_BOUND,
            'bound_at' => now(),
        ]);
    }

    /**
     * One projected row: its entity (by the mapping's expected key), an
     * immutable revision, and an occurrence in the package.
     *
     * @param  array<string, string|null>  $values
     */
    protected function ehiDocRow(string $table, array $values, ?PhrEhiPackage $package = null): PhrEhiRevision
    {
        $package ??= $this->docPackage;
        $keyColumns = app(EhiDocumentMapping::class)->expectedKeys()[$table];
        $key = [];
        foreach ($keyColumns as $column) {
            $key[$column] = $values[$column] ?? null;
        }
        $entity = PhrEhiEntity::query()->firstOrCreate(
            ['source_id' => $this->docSource->id, 'table_name' => $table, 'key_digest' => EhiMappingContext::keyDigest($key)],
            ['patient_id' => $this->docPatient->id, 'key_json' => $key],
        );
        $revision = PhrEhiRevision::query()->firstOrCreate(
            ['entity_id' => $entity->id, 'content_digest' => EhiCanonicalJson::digest($values)],
            ['patient_id' => $this->docPatient->id, 'first_package_id' => $package->id, 'values_json' => $values],
        );
        PhrEhiOccurrence::query()->create([
            'patient_id' => $this->docPatient->id,
            'package_id' => $package->id,
            'member_id' => $this->ehiDocTableMember($package, $table)->id,
            'revision_id' => $revision->id,
            'line_number' => ++$this->docLine,
            'byte_offset' => $this->docLine * 100,
            'disposition' => PhrEhiOccurrence::DISPOSITION_PENDING_REVIEW,
        ]);

        return $revision;
    }

    /**
     * A PAT_ENC row and an HNO_INFO note on it; returns the note revision.
     *
     * @param  array<string, string|null>  $extra
     */
    protected function ehiDocOwnedNote(string $noteId, string $csn, array $extra = [], string $patientKey = self::DOC_PATIENT_KEY, ?PhrEhiPackage $package = null): PhrEhiRevision
    {
        $this->ehiDocRow('PAT_ENC', ['PAT_ENC_CSN_ID' => $csn, 'PAT_ID' => $patientKey, 'CONTACT_DATE' => '2024-03-05'], $package);

        return $this->ehiDocRow('HNO_INFO', [
            'NOTE_ID' => $noteId,
            'PAT_ENC_CSN_ID' => $csn,
            'IP_NOTE_TYPE_C_NAME' => 'Progress Notes',
            'CREATE_INSTANT_DTTM' => '2024-03-05 14:30:00',
            'UNSIGNED_YN' => 'N',
            ...$extra,
        ], $package);
    }

    /** A note/media member with preserved bytes on the EHI disk. */
    protected function ehiDocMember(string $path, string $kind, string $bytes, ?PhrEhiPackage $package = null): PhrEhiPackageMember
    {
        $package ??= $this->docPackage;
        $sha = hash('sha256', $bytes);
        $blob = PhrEhiBlob::query()->where('patient_id', $this->docPatient->id)->where('sha256', $sha)->first();
        if (! $blob instanceof PhrEhiBlob) {
            $storagePath = PhrStorageKey::ehiBlob($this->docPatient->id, $sha);
            Storage::disk((string) config('phr.ehi_disk'))->put($storagePath, $bytes);
            $blob = PhrEhiBlob::query()->create([
                'patient_id' => $this->docPatient->id,
                'sha256' => $sha,
                'byte_size' => strlen($bytes),
                'storage_disk' => (string) config('phr.ehi_disk'),
                'storage_path' => $storagePath,
            ]);
        }

        return PhrEhiPackageMember::query()->create([
            'patient_id' => $this->docPatient->id,
            'package_id' => $package->id,
            'blob_id' => $blob->id,
            'path' => $path,
            'path_sha256' => hash('sha256', $path),
            'original_path' => $path,
            'byte_size' => strlen($bytes),
            'sha256' => $sha,
            'kind' => $kind,
            'table_name' => null,
        ]);
    }

    protected function ehiDocContext(?PhrEhiPackage $package = null): EhiMappingContext
    {
        return new EhiMappingContext($package ?? $this->docPackage, $this->docPatient, $this->docSource);
    }

    /** @return array{drafts: list<EhiProposalDraft>, findings: list<array{code: string, revision_id: int|null, detail: array<string, int|string|bool|null>}>} */
    protected function ehiDocPropose(?PhrEhiPackage $package = null): array
    {
        $context = $this->ehiDocContext($package);
        $drafts = [...app(EhiDocumentMapping::class)->propose($context)];

        return ['drafts' => $drafts, 'findings' => $context->findings()];
    }

    /** @param  list<EhiProposalDraft>  $drafts */
    protected function ehiDocDraftFor(array $drafts, string $field, string $value): EhiProposalDraft
    {
        $matches = array_values(array_filter($drafts, static fn (EhiProposalDraft $draft): bool => ($draft->proposed['ehi'][$field] ?? null) === $value));
        self::assertCount(1, $matches, "exactly one draft for {$field}");

        return $matches[0];
    }

    /** Persist a draft the way the proposal service does, capturing the target digest. */
    protected function ehiDocPersist(EhiProposalDraft $draft, ?PhrEhiPackage $package = null): PhrEhiProposal
    {
        $package ??= $this->docPackage;
        $target = $draft->targetId === null ? null : PhrDocument::withTrashed()->find($draft->targetId);
        $digests = PhrEhiRevision::query()->whereIn('id', $draft->revisionIds)->orderBy('content_digest')->pluck('content_digest')->all();

        $proposal = PhrEhiProposal::query()->create([
            'patient_id' => $this->docPatient->id,
            'package_id' => $package->id,
            'mapping' => EhiDocumentMapping::NAME,
            'mapping_version' => EhiDocumentMapping::VERSION,
            'subject_key' => $draft->subjectKey,
            'revision_digest' => hash('sha256', implode(',', $digests)),
            'kind' => $draft->kind,
            'target_table' => $draft->targetTable,
            'target_id' => $draft->targetId,
            'target_digest' => $target === null ? null : EhiTargetDigest::of($target),
            'proposed_json' => $draft->proposed,
            'before_json' => $draft->before,
            'candidate_ids_json' => $draft->candidateIds,
            'status' => PhrEhiProposal::STATUS_PENDING,
            'preview_digest' => hash('sha256', 'preview-'.$draft->subjectKey.'-'.$package->id),
        ]);
        PhrEhiProposalRevision::replaceFor($proposal, array_values(array_unique($draft->revisionIds)));

        return $proposal;
    }

    protected function ehiDocApply(PhrEhiProposal $proposal, string $decision = PhrEhiProposal::DECISION_ACCEPT, ?int $linkTargetId = null): EhiApplyOutcome
    {
        return DB::transaction(fn (): EhiApplyOutcome => app(EhiDocumentMapping::class)->apply(
            $proposal,
            new EhiApplyContext($this->docPatient, $this->docOwner->id, $decision, $linkTargetId, Carbon::parse('2026-10-19 12:00:00')),
        ));
    }

    /** Record evidence links for an applied proposal, as the review service does. */
    protected function ehiDocLink(PhrEhiProposal $proposal, EhiApplyOutcome $outcome): void
    {
        foreach (PhrEhiProposalRevision::idsFor($proposal) as $revisionId) {
            PhrEhiRecordLink::query()->firstOrCreate(
                ['revision_id' => $revisionId, 'document_id' => $outcome->recordId, 'link_kind' => $outcome->linkKind],
                ['patient_id' => $this->docPatient->id, 'proposal_id' => $proposal->id, 'mapping_version' => EhiDocumentMapping::VERSION, 'created_by_user_id' => $this->docOwner->id],
            );
        }
    }

    /** @return list<string> */
    protected function ehiDocDocumentObjects(): array
    {
        return Storage::disk(PhrDocument::STORAGE_DISK)->allFiles("patients/{$this->docPatient->id}/documents");
    }

    private function ehiDocTableMember(PhrEhiPackage $package, string $table): PhrEhiPackageMember
    {
        $key = $package->id.':'.$table;
        if (! isset($this->docTableMembers[$key])) {
            $this->docTableMembers[$key] = $this->ehiDocMember("{$table}.tsv", EhiMemberKind::TABLE, "synthetic {$table} member {$package->id}\n", $package);
            $this->docTableMembers[$key]->forceFill(['table_name' => $table])->save();
        }

        return $this->docTableMembers[$key];
    }
}
