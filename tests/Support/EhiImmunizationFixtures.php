<?php

namespace Tests\Support;

use App\Models\PhrEhiBlob;
use App\Models\PhrEhiEntity;
use App\Models\PhrEhiOccurrence;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrEhiSource;
use App\Models\PhrImmunization;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiMemberKind;
use App\Services\PHR\Import\Ehi\Mapping\EhiImmunizationMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiProposalDraft;
use App\Services\PHR\Import\Ehi\Mapping\EhiTargetDigest;
use Illuminate\Support\Carbon;

/**
 * Builds a bound synthetic EHI package with projected IMMUNE / PAT_IMMUNIZATIONS
 * evidence directly in the database, so the immunization mapping can be tested
 * without the parser or projection service. All values are synthetic.
 */
trait EhiImmunizationFixtures
{
    protected const string IMM_PATIENT_KEY = 'Z90000001';

    protected const string IMM_OTHER_PATIENT_KEY = 'Z90000002';

    protected User $immOwner;

    protected PhrPatient $immPatient;

    protected PhrEhiSource $immSource;

    protected PhrEhiPackage $immPackage;

    /** @var array<int, array<string, PhrEhiPackageMember>> package id => table => member */
    private array $immMembers = [];

    /** @var array<int, int> member id => last line number */
    private array $immLines = [];

    protected function setUpEhiImmunization(): void
    {
        $this->immOwner = $this->createUser();
        $this->immPatient = PhrPatient::query()->create([
            'owner_user_id' => $this->immOwner->id,
            'display_name' => 'Synthetic Patient',
            'relationship' => 'self',
        ]);
        $this->immSource = PhrEhiSource::query()->create([
            'patient_id' => $this->immPatient->id,
            'created_by_user_id' => $this->immOwner->id,
            'label' => 'Synthetic Clinic',
            'source_patient_key' => self::IMM_PATIENT_KEY,
        ]);
        $this->immPackage = $this->immNewPackage();
    }

    protected function immNewPackage(): PhrEhiPackage
    {
        return PhrEhiPackage::query()->create([
            'patient_id' => $this->immPatient->id,
            'source_id' => $this->immSource->id,
            'created_by_user_id' => $this->immOwner->id,
            'bound_by_user_id' => $this->immOwner->id,
            'manifest_digest' => hash('sha256', 'synthetic-package-'.bin2hex(random_bytes(8))),
            'intake_kind' => PhrEhiPackage::INTAKE_DIRECTORY,
            'detected_source_patient_key' => self::IMM_PATIENT_KEY,
            'preservation_status' => PhrEhiPackage::PRESERVATION_PRESERVED,
            'accounting_status' => PhrEhiPackage::ACCOUNTING_ACCOUNTED,
            'binding_status' => PhrEhiPackage::BINDING_BOUND,
            'bound_at' => Carbon::now(),
        ]);
    }

    /**
     * A synthetic IMMUNE row. Defaults describe a plain given dose; pass null to
     * drop a column and a string to override it.
     *
     * @param  array<string, string|null>  $overrides
     */
    protected function immImmune(string $immuneId, array $overrides = [], ?PhrEhiPackage $package = null): PhrEhiRevision
    {
        $values = [
            'IMMUNE_ID' => $immuneId,
            'IMMUNZATN_ID' => '9001',
            'IMMUNZATN_ID_NAME' => 'SYNTHETIC VACCINE A',
            'IMMUNE_DATE' => '2024-03-05',
            'IMMNZTN_STATUS_C_NAME' => 'Given',
            'IMM_HISTORIC_ADM_YN' => 'N',
            ...$overrides,
        ];

        return $this->immRow(EhiImmunizationMapping::TABLE_IMMUNE, ['IMMUNE_ID' => $immuneId], array_filter($values, static fn (?string $value): bool => $value !== null), $package);
    }

    protected function immPatientLink(string $patId, string $line, ?string $immuneId, ?PhrEhiPackage $package = null): PhrEhiRevision
    {
        $values = ['PAT_ID' => $patId, 'LINE' => $line, 'IMMUNE_ID' => $immuneId];

        return $this->immRow(EhiImmunizationMapping::TABLE_PAT_IMMUNIZATIONS, ['PAT_ID' => $patId, 'LINE' => $line], $values, $package);
    }

    /**
     * An IMMUNE row owned by the bound patient through one PAT_IMMUNIZATIONS line.
     *
     * @param  array<string, string|null>  $overrides
     */
    protected function immOwnedImmune(string $immuneId, array $overrides = [], ?PhrEhiPackage $package = null, ?string $line = null): PhrEhiRevision
    {
        $revision = $this->immImmune($immuneId, $overrides, $package);
        $this->immPatientLink(self::IMM_PATIENT_KEY, $line ?? $immuneId, $immuneId, $package);

        return $revision;
    }

    /**
     * @param  array<string, string|null>  $key
     * @param  array<string, string|null>  $values
     */
    protected function immRow(string $table, array $key, array $values, ?PhrEhiPackage $package = null): PhrEhiRevision
    {
        $package ??= $this->immPackage;
        $entity = PhrEhiEntity::query()->firstOrCreate(
            ['source_id' => $this->immSource->id, 'table_name' => $table, 'key_digest' => EhiMappingContext::keyDigest($key)],
            ['patient_id' => $this->immPatient->id, 'key_json' => $key],
        );
        $contentDigest = hash('sha256', json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $revision = PhrEhiRevision::query()->firstOrCreate(
            ['entity_id' => $entity->id, 'content_digest' => $contentDigest],
            ['patient_id' => $this->immPatient->id, 'first_package_id' => $package->id, 'values_json' => $values],
        );

        $member = $this->immMember($package, $table);
        $line = ($this->immLines[$member->id] ?? 1) + 1;
        $this->immLines[$member->id] = $line;
        PhrEhiOccurrence::query()->create([
            'patient_id' => $this->immPatient->id,
            'package_id' => $package->id,
            'member_id' => $member->id,
            'revision_id' => $revision->id,
            'line_number' => $line,
            'byte_offset' => $line * 100,
            'disposition' => PhrEhiOccurrence::DISPOSITION_PENDING_REVIEW,
        ]);

        return $revision;
    }

    protected function immContext(?PhrEhiPackage $package = null): EhiMappingContext
    {
        return new EhiMappingContext($package ?? $this->immPackage, $this->immPatient, $this->immSource);
    }

    /** @return list<EhiProposalDraft> */
    protected function immPropose(?EhiMappingContext $context = null): array
    {
        $context ??= $this->immContext();

        return array_values([...(new EhiImmunizationMapping)->propose($context)]);
    }

    /** Persist a draft the way the proposal service would, capturing the target digest. */
    protected function immPersist(EhiProposalDraft $draft, ?PhrEhiPackage $package = null): PhrEhiProposal
    {
        $target = $draft->targetId === null ? null : PhrImmunization::query()->withTrashed()->find($draft->targetId);

        return PhrEhiProposal::query()->create([
            'patient_id' => $this->immPatient->id,
            'package_id' => ($package ?? $this->immPackage)->id,
            'mapping' => EhiImmunizationMapping::NAME,
            'mapping_version' => EhiImmunizationMapping::VERSION,
            'subject_key' => $draft->subjectKey,
            'revision_digest' => hash('sha256', implode(',', $draft->revisionIds)),
            'revision_ids_json' => $draft->revisionIds,
            'kind' => $draft->kind,
            'target_table' => $draft->targetTable,
            'target_id' => $draft->targetId,
            'target_digest' => $target === null ? null : EhiTargetDigest::of($target),
            'proposed_json' => $draft->proposed,
            'before_json' => $draft->before,
            'candidate_ids_json' => $draft->candidateIds,
            'preview_digest' => hash('sha256', $draft->subjectKey.$draft->kind),
        ]);
    }

    protected function immLink(PhrEhiRevision $revision, PhrImmunization $record, string $kind = PhrEhiRecordLink::KIND_PROJECTED): PhrEhiRecordLink
    {
        return PhrEhiRecordLink::query()->create([
            'patient_id' => $this->immPatient->id,
            'revision_id' => $revision->id,
            'immunization_id' => $record->id,
            'link_kind' => $kind,
            'mapping_version' => EhiImmunizationMapping::VERSION,
            'created_by_user_id' => $this->immOwner->id,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function immRecord(array $attributes = []): PhrImmunization
    {
        return PhrImmunization::query()->create([
            'patient_id' => $this->immPatient->id,
            'user_id' => $this->immOwner->id,
            'review_status' => 'confirmed',
            'vaccine_name' => 'SYNTHETIC VACCINE A',
            'administered_on' => '2024-03-05',
            ...$attributes,
        ]);
    }

    private function immMember(PhrEhiPackage $package, string $table): PhrEhiPackageMember
    {
        if (isset($this->immMembers[$package->id][$table])) {
            return $this->immMembers[$package->id][$table];
        }
        $sha = hash('sha256', $package->id.'/'.$table);
        $blob = PhrEhiBlob::query()->create([
            'patient_id' => $this->immPatient->id,
            'sha256' => $sha,
            'byte_size' => 1,
            'storage_disk' => 'local',
            'storage_path' => 'synthetic/'.$sha,
        ]);

        return $this->immMembers[$package->id][$table] = PhrEhiPackageMember::query()->create([
            'patient_id' => $this->immPatient->id,
            'package_id' => $package->id,
            'blob_id' => $blob->id,
            'path' => $table.'.tsv',
            'path_sha256' => hash('sha256', $table.'.tsv'),
            'original_path' => $table.'.tsv',
            'byte_size' => 1,
            'sha256' => $sha,
            'kind' => EhiMemberKind::TABLE,
            'table_name' => $table,
        ]);
    }
}
