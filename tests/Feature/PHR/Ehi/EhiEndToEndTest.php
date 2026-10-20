<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrDocument;
use App\Models\PhrEhiBlob;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiProposalDecision;
use App\Models\PhrEhiProposalRevision;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiSource;
use App\Models\PhrImmunization;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\DataHub\PhrPatientDeletionCleanupService;
use App\Services\PHR\DataHub\PhrPatientDeletionService;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\EhiReviewRefused;
use App\Services\PHR\Import\Ehi\Mapping\EhiDocumentMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiImmunizationMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use App\Services\PHR\NativeBackup\PhrNativeTableCompatibility;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\TestCase;

/**
 * The real registry and both real mappings, end to end: intake, bind, preview,
 * review (CLI and service), re-intake convergence, a changed second package,
 * withdrawal, native backup and restore, and patient deletion. All synthetic.
 */
class EhiEndToEndTest extends TestCase
{
    use EhiIntakeFixtures;

    private const string FHIR_SOURCE = 'fhir:synthetic';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake('phr_dicom');
        Storage::fake('phr_exports');
        Queue::fake();
        // The production registry, not a test double.
        config(['phr.ehi_mappings' => [EhiImmunizationMapping::class, EhiDocumentMapping::class]]);
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    public function test_real_mappings_from_intake_through_review_restore_and_deletion(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $fhir = PhrImmunization::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'import_source' => self::FHIR_SOURCE,
            'external_id' => 'Immunization/synthetic-1',
            'review_status' => PhrReviewStatus::CONFIRMED,
            'vaccine_name' => 'Synthetic Vaccine D',
            'administered_on' => '2023-10-01',
        ]);
        $fhirDigest = $this->recordDigest($fhir->refresh());
        $base = ['--patient' => $patient->id, '--actor' => $owner->id];
        $service = app(EhiProposalService::class);

        // ---- package 1 through the CLI: intake, bind, preview.
        $dir = $this->ehiDirectory(EhiClinicalPackage::standard()->build(), 'package-1');
        [$exit, $intake] = $this->artisan2('phr:ehi:intake', [...$base, '--dir' => $dir]);
        self::assertSame(0, $exit, $intake);
        self::assertStringContainsString('replayed: no', $intake);
        $package = PhrEhiPackage::query()->sole();
        [$exit, $bind] = $this->artisan2('phr:ehi:bind', [...$base, '--package' => $package->id, '--new-source-label' => 'Synthetic Clinic', '--yes' => true]);
        self::assertSame(0, $exit, $bind);
        $source = PhrEhiSource::query()->sole();
        self::assertSame(EhiClinicalPackage::PATIENT_KEY, $source->source_patient_key);

        [$exit, $preview] = $this->artisan2('phr:ehi:preview', [...$base, '--package' => $package->id]);
        self::assertSame(0, $exit, $preview);
        foreach ([
            'clinical: projected',
            'projection: tables projected 8, key mismatch 0, absent 0',
            'proposals immunization: create 3, possible_duplicate 1, retain 2',
            'proposals document: create 2, retain 1',
            'statuses: pending 9',
            'mapping findings: orphan_immunization 1, unlinked_note_member 1',
        ] as $expected) {
            self::assertStringContainsString($expected, $preview);
        }
        $this->assertNoSourceValues($preview);
        self::assertSame(1, PhrImmunization::query()->count(), 'a preview writes no clinical rows');
        self::assertSame(0, PhrDocument::query()->count());

        [$exit, $status] = $this->artisan2('phr:ehi:status', [...$base, '--package' => $package->id]);
        self::assertSame(0, $exit, $status);
        self::assertStringContainsString('projection: tables projected 8, key mismatch 0, absent 0', $status);
        self::assertStringContainsString('proposals: pending 9', $status);
        self::assertStringContainsString('pending: document create 2, document retain 1, immunization create 3, immunization possible_duplicate 1, immunization retain 2', $status);
        self::assertStringNotContainsString('in progress', $status);
        $this->assertNoSourceValues($status);

        $kinds = [
            '601' => PhrEhiProposal::KIND_CREATE,
            '602' => PhrEhiProposal::KIND_CREATE,
            '603' => PhrEhiProposal::KIND_CREATE,
            '604' => PhrEhiProposal::KIND_RETAIN,
            '605' => PhrEhiProposal::KIND_RETAIN,
            '606' => PhrEhiProposal::KIND_POSSIBLE_DUPLICATE,
        ];
        foreach ($kinds as $immuneId => $kind) {
            self::assertSame($kind, $this->immune($package, $immuneId)->kind, "IMMUNE {$immuneId}");
        }
        self::assertSame('status_deleted', $this->immune($package, '604')->proposed_json['retain_reason']);
        self::assertSame('orphan_immunization', $this->immune($package, '605')->proposed_json['retain_reason']);
        self::assertSame([$fhir->id], $this->immune($package, '606')->candidate_ids_json);
        self::assertSame(PhrEhiProposal::KIND_CREATE, $this->note($package, '88001')->kind);
        self::assertSame(PhrEhiProposal::KIND_CREATE, $this->note($package, '88002')->kind);
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $this->note($package, '88003')->kind);
        self::assertSame('deleted', $this->note($package, '88003')->proposed_json['reason']);
        [$exit, $show] = $this->artisan2('phr:ehi:proposals', [...$base, '--show' => $this->note($package, '88001')->id]);
        self::assertSame(0, $exit, $show);
        foreach (['kind: create', 'title: proposed', 'extracted_text: proposed', 'observed_at: proposed'] as $expected) {
            self::assertStringContainsString($expected, $show);
        }
        $this->assertNoSourceValues($show);

        // ---- review package 1: accept / reject / retain / link, CLI and service.
        $this->reviewCli($base, $this->immune($package, '601'), 'accept');
        $service->review($this->immune($package, '602'), PhrEhiProposal::DECISION_ACCEPT, $owner->id, $this->immune($package, '602')->preview_digest);
        $this->reviewCli($base, $this->immune($package, '603'), 'accept');
        $service->review($this->immune($package, '604'), PhrEhiProposal::DECISION_RETAIN, $owner->id, $this->immune($package, '604')->preview_digest);
        $this->reviewCli($base, $this->immune($package, '605'), 'reject');
        $this->reviewCli($base, $this->immune($package, '606'), 'link_existing', $fhir->id);
        $this->reviewCli($base, $this->note($package, '88001'), 'accept');
        $service->review($this->note($package, '88002'), PhrEhiProposal::DECISION_ACCEPT, $owner->id, $this->note($package, '88002')->preview_digest);
        $this->reviewCli($base, $this->note($package, '88003'), 'retain_evidence');

        $statuses = ['601' => 'accepted', '602' => 'accepted', '603' => 'accepted', '604' => 'retained', '605' => 'rejected', '606' => 'linked'];
        foreach ($statuses as $immuneId => $expected) {
            self::assertSame($expected, $this->immune($package, $immuneId)->status, "IMMUNE {$immuneId}");
        }

        // Accepted immunizations: confirmed, exact dose text, never a CVX or dose number.
        $first = $this->recordFor($source, '601');
        self::assertSame(PhrReviewStatus::CONFIRMED, $first->review_status);
        self::assertSame('0.50', $first->dose_amount);
        self::assertSame('mL', $first->dose_unit);
        self::assertNull($first->cvx_code);
        self::assertNull($first->dose_number);
        self::assertSame('given', $first->administration_status);
        self::assertSame('2024-03-05|2024-03-05 09:15', $first->administered_at_source);
        self::assertSame('SYN-LOT-1', $first->lot_number);
        self::assertSame('1.0', $this->recordFor($source, '602')->dose_amount);
        self::assertSame('2024-03-05', $this->recordFor($source, '602')->administered_on?->format('Y-m-d'));
        self::assertSame('historical', $this->recordFor($source, '603')->administration_status);
        self::assertNull(PhrImmunization::withTrashed()->where('external_id', 'IMMUNE:604')->first());
        self::assertNull(PhrImmunization::withTrashed()->where('external_id', 'IMMUNE:605')->first());
        self::assertSame(4, PhrImmunization::query()->count());
        self::assertSame($fhirDigest, $this->recordDigest($fhir->refresh()), 'a linked record is never rewritten');

        // Evidence links and append-only decision history.
        foreach (['601', '602', '603'] as $immuneId) {
            $proposal = $this->immune($package, $immuneId);
            $record = $this->recordFor($source, $immuneId);
            self::assertSame(count(PhrEhiProposalRevision::idsFor($proposal)), PhrEhiRecordLink::query()->where('immunization_id', $record->id)->where('link_kind', PhrEhiRecordLink::KIND_PROJECTED)->count());
            self::assertCount(2, PhrEhiProposalRevision::idsFor($proposal), 'IMMUNE and PAT_IMMUNIZATIONS revisions');
        }
        self::assertSame(2, PhrEhiRecordLink::query()->where('immunization_id', $fhir->id)->where('link_kind', PhrEhiRecordLink::KIND_BRIDGED)->count());
        self::assertSame(0, PhrEhiRecordLink::query()->whereIn('proposal_id', [$this->immune($package, '604')->id, $this->immune($package, '605')->id, $this->note($package, '88003')->id])->count());
        $history = PhrEhiProposalDecision::query()->orderBy('id')->get();
        self::assertCount(9, $history);
        self::assertSame(['pending'], $history->pluck('previous_status')->unique()->values()->all());
        self::assertSame($fhir->id, $history->firstWhere('decision', PhrEhiProposal::DECISION_LINK)?->linked_immunization_id);
        self::assertSame(['accept' => 5, 'link_existing' => 1, 'reject' => 1, 'retain_evidence' => 2], $history->countBy('decision')->sortKeys()->all());

        // Documents: derived text, a verified RTF copy, and untouched evidence.
        $plain = $this->documentFor($source, 'NOTE:88001');
        self::assertSame(EhiClinicalPackage::NOTE_88001_TEXT, $plain->extracted_text);
        self::assertSame('text/plain', $plain->mime_type);
        self::assertSame(EhiDocumentMapping::SOURCE, $plain->source);
        self::assertContains(EhiDocumentMapping::TAG_DERIVED_TEXT, $plain->tags);
        self::assertSame(EhiClinicalPackage::NOTE_88001_TEXT, Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $plain->storage_path));
        self::assertSame('2024-03-05 14:30:00', $plain->observed_at?->format('Y-m-d H:i:s'));
        self::assertSame(7, PhrEhiRecordLink::query()->where('document_id', $plain->id)->count(), 'note, encounter, two contacts, three text lines');

        $scanned = $this->documentFor($source, 'NOTE:88002');
        self::assertSame("Synthetic scanned note line one.\nSynthetic scanned note line two.", $scanned->extracted_text);
        self::assertSame('application/rtf', $scanned->mime_type);
        self::assertNotContains(EhiDocumentMapping::TAG_DERIVED_TEXT, $scanned->tags);
        self::assertSame(EhiClinicalPackage::RTF_BYTES, Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $scanned->storage_path));
        self::assertSame(hash('sha256', EhiClinicalPackage::RTF_BYTES), $scanned->file_hash);
        $rtfBlob = $this->blobFor($package, EhiClinicalPackage::RTF_MEMBER);
        self::assertNotSame($rtfBlob->storage_path, $scanned->storage_path, 'the document owns a copy, never the evidence object');
        self::assertSame(EhiClinicalPackage::RTF_BYTES, Storage::disk($rtfBlob->storage_disk)->get($rtfBlob->storage_path));
        self::assertSame(count(PhrEhiProposalRevision::idsFor($this->note($package, '88002'))), PhrEhiRecordLink::query()->where('document_id', $scanned->id)->count());
        self::assertSame(2, PhrDocument::query()->count());

        // ---- re-intake of the same bytes and a re-preview converge.
        $snapshot = $this->snapshot();
        [$exit, $again] = $this->artisan2('phr:ehi:intake', [...$base, '--dir' => $dir]);
        self::assertSame(0, $exit, $again);
        self::assertStringContainsString('replayed: yes', $again);
        [$exit, $repreview] = $this->artisan2('phr:ehi:preview', [...$base, '--package' => $package->id]);
        self::assertSame(0, $exit, $repreview);
        self::assertStringContainsString('(already projected)', $repreview);
        self::assertStringContainsString('statuses: accepted 5, linked 1, rejected 1, retained 2', $repreview);
        self::assertSame($snapshot, $this->snapshot(), 'no new packages, evidence, proposals, records, or documents');

        // ---- a changed second package: one dose's lot changed, one new dose.
        $changed = EhiClinicalPackage::standard()
            ->changing('IMMUNE', ['IMMUNE_ID' => '601'], ['LOT' => 'SYN-LOT-1B'])
            ->changing('HNO_INFO', ['NOTE_ID' => '88002'], ['NOTE_DESC' => 'Synthetic scanned note, amended'])
            ->adding('IMMUNE', ['IMMUNE_ID' => '607', 'IMMUNZATN_ID' => '9607', 'IMMUNZATN_ID_NAME' => 'SYNTHETIC VACCINE F', 'IMMUNE_DATE' => '2024-09-09', 'IMMNZTN_STATUS_C_NAME' => 'Given', 'IMM_HISTORIC_ADM_YN' => 'N', 'IMMNZTN_DOSE_AMOUNT' => '0.25'])
            ->adding('PAT_IMMUNIZATIONS', ['PAT_ID' => EhiClinicalPackage::PATIENT_KEY, 'LINE' => '6', 'IMMUNE_ID' => '607']);
        [$exit, $intake2] = $this->artisan2('phr:ehi:intake', [...$base, '--dir' => $this->ehiDirectory($changed->build(), 'package-2')]);
        self::assertSame(0, $exit, $intake2);
        $second = PhrEhiPackage::query()->whereKeyNot($package->id)->sole();
        [$exit] = $this->artisan2('phr:ehi:bind', [...$base, '--package' => $second->id, '--source' => $source->id, '--yes' => true]);
        self::assertSame(0, $exit);
        $preview2 = $service->preview($second, $patient, $owner->id);
        self::assertSame([
            'immunization' => ['corroborate' => 3, 'create' => 1, 'retain' => 2, 'update' => 1],
            'document' => ['corroborate' => 1, 'retain' => 1, 'update' => 1],
        ], $preview2->counts);
        $kinds2 = ['601' => 'update', '602' => 'corroborate', '603' => 'corroborate', '604' => 'retain', '605' => 'retain', '606' => 'corroborate', '607' => 'create'];
        foreach ($kinds2 as $immuneId => $kind) {
            self::assertSame($kind, $this->immune($second, $immuneId)->kind, "IMMUNE {$immuneId} in package 2");
        }
        $update = $this->immune($second, '601');
        self::assertSame($first->id, $update->target_id);
        self::assertSame('SYN-LOT-1B', $update->proposed_json['lot_number']);
        self::assertSame('SYN-LOT-1', $update->before_json['lot_number']);
        self::assertSame(['lot_number', 'raw_text'], array_keys($update->proposed_json));
        self::assertSame($fhir->id, $this->immune($second, '606')->target_id);
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $this->note($second, '88001')->kind);

        // A document update changes only the amended field, never the stored file.
        $documentUpdate = $this->note($second, '88002');
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $documentUpdate->kind);
        self::assertSame($scanned->id, $documentUpdate->target_id);
        [$exit, $show] = $this->artisan2('phr:ehi:proposals', [...$base, '--show' => $documentUpdate->id]);
        self::assertSame(0, $exit, $show);
        self::assertStringContainsString('title: changed', $show);
        self::assertStringContainsString('extracted_text: unchanged', $show);
        $this->assertNoSourceValues($show);
        [$storedPath, $storedText] = [$scanned->storage_path, $scanned->extracted_text];
        $this->reviewCli($base, $documentUpdate, 'approve_update');
        $scanned->refresh();
        self::assertSame('Synthetic scanned note, amended', $scanned->title);
        self::assertSame($storedPath, $scanned->storage_path);
        self::assertSame($storedText, $scanned->extracted_text);
        self::assertSame(EhiClinicalPackage::RTF_BYTES, Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $scanned->storage_path));
        self::assertSame(4, PhrImmunization::query()->count(), 'a preview writes no clinical rows');

        // A record edited after the preview refuses the update; a re-preview
        // issues a new digest, and the old one is refused as stale.
        $staleDigest = $update->preview_digest;
        $first->forceFill(['notes' => 'Synthetic reviewer note'])->save();
        $this->assertRefused(EhiReviewRefused::STALE_TARGET, fn () => $service->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $staleDigest));
        $service->preview($second, $patient, $owner->id);
        $update->refresh();
        self::assertNotSame($staleDigest, $update->preview_digest);
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
        $this->assertRefused(EhiReviewRefused::STALE_PREVIEW, fn () => $service->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $staleDigest));
        $this->assertRefused(EhiReviewRefused::DECISION_NOT_ALLOWED, fn () => $service->review($update, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $update->preview_digest));
        $this->reviewCli($base, $update, 'approve_update');
        $first->refresh();
        self::assertSame('SYN-LOT-1B', $first->lot_number);
        self::assertSame('Synthetic reviewer note', $first->notes, 'an unmapped field a person edited is kept');
        self::assertSame(PhrReviewStatus::CONFIRMED, $first->review_status);
        self::assertSame('0.50', $first->dose_amount);

        $this->reviewCli($base, $this->immune($second, '602'), 'accept');
        self::assertSame(2, PhrEhiRecordLink::query()->where('immunization_id', $this->recordFor($source, '602')->id)->where('link_kind', PhrEhiRecordLink::KIND_CORROBORATING)->count());
        $service->review($this->immune($second, '607'), PhrEhiProposal::DECISION_ACCEPT, $owner->id, $this->immune($second, '607')->preview_digest);
        self::assertSame('0.25', $this->recordFor($source, '607')->dose_amount);

        // ---- a withdrawn record is retained, never resurrected.
        $historical = $this->recordFor($source, '603');
        $corroborate = $this->immune($second, '603');
        $historical->delete();
        $service->preview($second, $patient, $owner->id);
        $corroborate->refresh();
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $corroborate->kind);
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $corroborate->status);
        self::assertSame(EhiImmunizationMapping::FINDING_LINKED_RECORD_WITHDRAWN, $corroborate->proposed_json['retain_reason']);
        $this->assertRefused(EhiReviewRefused::DECISION_NOT_ALLOWED, fn () => $service->review($corroborate, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $corroborate->preview_digest));
        $service->review($corroborate, PhrEhiProposal::DECISION_RETAIN, $owner->id, $corroborate->preview_digest);
        self::assertNotNull(PhrImmunization::withTrashed()->findOrFail($historical->id)->deleted_at);
        self::assertSame(1, PhrImmunization::withTrashed()->where('external_id', 'IMMUNE:603')->count());
        self::assertSame(PhrEhiProposal::STATUS_ACCEPTED, $this->immune($package, '603')->status, 'package 1 decisions are never rewritten');

        // ---- native backup, then patient deletion, then restore.
        $counts = $this->evidenceCounts((int) $patient->id);
        $records = [
            'immunizations' => PhrImmunization::withTrashed()->where('patient_id', $patient->id)->count(),
            'documents' => PhrDocument::query()->where('patient_id', $patient->id)->count(),
        ];
        $backups = app(PhrNativeBackupService::class);
        $backup = $backups->generate($backups->createQueuedBackup($patient, $owner->id));
        $archive = tempnam(sys_get_temp_dir(), 'ehi-e2e-');
        self::assertIsString($archive);
        self::assertTrue(copy(Storage::disk('phr_exports')->path((string) $backup->storage_path), $archive));

        $documentKeys = PhrDocument::query()->where('patient_id', $patient->id)->pluck('storage_path')->all();
        $blobKeys = PhrEhiBlob::query()->where('patient_id', $patient->id)->pluck('storage_path')->all();
        self::assertNotEmpty($blobKeys);
        $deletions = app(PhrPatientDeletionService::class);
        $plan = $deletions->preview($patient);
        self::assertSame([], $plan->blockers);
        $deletion = $deletions->delete($patient, $owner->id, $plan->digest, true);
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);
        foreach ([...array_keys($counts), 'phr_ehi_packages', 'phr_ehi_blobs', 'phr_ehi_sources', 'phr_ehi_package_members', 'phr_ehi_findings', 'phr_immunizations', 'phr_documents'] as $table) {
            self::assertSame(0, DB::table($table)->where('patient_id', $patient->id)->count(), "{$table} rows survived deletion");
        }
        foreach ([...$documentKeys, ...$blobKeys] as $key) {
            Storage::disk(PhrDocument::STORAGE_DISK)->assertMissing((string) $key);
        }
        self::assertSame([], Storage::disk(PhrDocument::STORAGE_DISK)->allFiles("patients/{$patient->id}"));

        $restore = app(PhrNativeRestoreService::class);
        $attempt = $restore->preview($restore->createPreview(new UploadedFile($archive, 'synthetic-native.zip', 'application/zip', null, true), $owner->id, false));
        self::assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);
        $restore->apply($attempt->refresh());
        self::assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $attempt->refresh()->status, (string) $attempt->failure_category);
        @unlink($archive);

        $restoredId = (int) $attempt->target_patient_root_id;
        self::assertSame([], DB::select('PRAGMA foreign_key_check'));
        self::assertSame($counts, $this->evidenceCounts($restoredId));
        self::assertSame($records['immunizations'], PhrImmunization::withTrashed()->where('patient_id', $restoredId)->count());
        self::assertSame($records['documents'], PhrDocument::query()->where('patient_id', $restoredId)->count());
        $restoredLinks = DB::table('phr_ehi_record_links')->where('patient_id', $restoredId)->get();
        foreach ($restoredLinks as $link) {
            $table = $link->immunization_id !== null ? 'phr_immunizations' : 'phr_documents';
            self::assertSame($restoredId, (int) DB::table($table)->where('id', $link->immunization_id ?? $link->document_id)->value('patient_id'));
        }
        self::assertSame(
            ['accepted' => 9, 'linked' => 1, 'pending' => 5, 'rejected' => 1, 'retained' => 3],
            DB::table('phr_ehi_proposals')->where('patient_id', $restoredId)->selectRaw('status, count(*) as c')->groupBy('status')->orderBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c)->all(),
        );
        foreach (PhrDocument::query()->where('patient_id', $restoredId)->get() as $document) {
            self::assertSame($document->file_hash, hash('sha256', (string) Storage::disk((string) $document->storage_disk)->get((string) $document->storage_path)));
        }
        foreach (PhrEhiBlob::query()->where('patient_id', $restoredId)->get() as $blob) {
            self::assertSame($blob->sha256, hash('sha256', (string) Storage::disk($blob->storage_disk)->get($blob->storage_path)));
        }

        // A restored pending proposal is re-previewed before review, and the
        // re-preview converges on the restored evidence.
        $restoredPatient = PhrPatient::query()->findOrFail($restoredId);
        PhrPatientUserAccess::query()->firstOrCreate(['patient_id' => $restoredId, 'user_id' => $owner->id], ['access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $restoredSecond = PhrEhiPackage::query()->where('patient_id', $restoredId)->where('manifest_digest', $second->manifest_digest)->sole();
        $restoredBridge = $this->immune($restoredSecond, '606');
        $this->assertRefused(EhiReviewRefused::PREVIEW_INCOMPLETE, fn () => $service->review($restoredBridge, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $restoredBridge->preview_digest));
        $restoredPreview = $service->preview($restoredSecond, $restoredPatient, $owner->id);
        self::assertSame(['corroborate' => 4, 'retain' => 3], $restoredPreview->counts['immunization'], 'decided subjects now corroborate; 603 stays withdrawn');
        self::assertSame($counts, $this->evidenceCounts($restoredId));
        $restoredBridge->refresh();
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $restoredBridge->kind);
        $restoredFhir = PhrImmunization::query()->where('patient_id', $restoredId)->where('import_source', self::FHIR_SOURCE)->sole();
        self::assertSame($restoredFhir->id, $restoredBridge->target_id);
        $service->review($restoredBridge, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $restoredBridge->preview_digest);
        self::assertSame(PhrEhiProposal::STATUS_ACCEPTED, $restoredBridge->refresh()->status);

        // The restored graph deletes cleanly too, document copies and EHI blobs included.
        $plan = $deletions->preview($restoredPatient);
        self::assertSame([], $plan->blockers);
        $deletion = $deletions->delete($restoredPatient, $owner->id, $plan->digest, true);
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);
        self::assertSame([], Storage::disk(PhrDocument::STORAGE_DISK)->allFiles("patients/{$restoredId}"));
        foreach (array_keys($counts) as $table) {
            self::assertSame(0, DB::table($table)->count(), "{$table} rows survived deletion of the restored patient");
        }
    }

    public function test_a_document_copy_is_removed_when_the_review_transaction_rolls_back(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $existingKey = "patients/{$patient->id}/documents/synthetic-existing/existing.txt";
        Storage::disk(PhrDocument::STORAGE_DISK)->put($existingKey, 'synthetic existing document');
        $existing = PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $existingKey,
            'byte_size' => 27,
        ]);
        $package = $this->ehiIntakeDirectory(EhiClinicalPackage::standard()->build(), $patient, (int) $owner->id)->package;
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, null, 'Synthetic Clinic');
        $service = app(EhiProposalService::class);
        $service->preview($package, $patient, $owner->id);
        $objects = Storage::disk(PhrDocument::STORAGE_DISK)->allFiles();
        $rtfBlob = $this->blobFor($package, EhiClinicalPackage::RTF_MEMBER);

        // The mapping copies the file, then the evidence-link insert fails.
        DB::statement("CREATE TRIGGER synthetic_link_failure BEFORE INSERT ON phr_ehi_record_links BEGIN SELECT RAISE(ABORT, 'synthetic link failure'); END");
        foreach (['88002', '88001'] as $noteId) {
            $proposal = $this->note($package, $noteId);
            try {
                $service->review($proposal, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $proposal->preview_digest);
                self::fail('The link insert must fail.');
            } catch (QueryException) {
                // Expected: not a refusal, the transaction rolled back.
            }
            self::assertSame(PhrEhiProposal::STATUS_PENDING, $proposal->refresh()->status);
            self::assertSame(0, PhrEhiProposalDecision::query()->count());
        }
        self::assertSame(1, PhrDocument::withTrashed()->count());
        self::assertSame($objects, Storage::disk(PhrDocument::STORAGE_DISK)->allFiles(), 'no copied object survives the rollback');
        self::assertSame(EhiClinicalPackage::RTF_BYTES, Storage::disk($rtfBlob->storage_disk)->get($rtfBlob->storage_path));
        self::assertSame('synthetic existing document', Storage::disk(PhrDocument::STORAGE_DISK)->get($existingKey));
        self::assertTrue($existing->refresh()->exists);

        // Once the failure is gone the same proposal applies and its copy stays.
        DB::statement('DROP TRIGGER synthetic_link_failure');
        $proposal = $this->note($package, '88002');
        $service->review($proposal, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $proposal->preview_digest);
        $document = PhrDocument::query()->where('external_id', 'NOTE:88002')->sole();
        self::assertSame(EhiClinicalPackage::RTF_BYTES, Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $document->storage_path));
        self::assertCount(count($objects) + 1, Storage::disk(PhrDocument::STORAGE_DISK)->allFiles());
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);

        return [$owner, $patient];
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function reviewCli(array $base, PhrEhiProposal $proposal, string $decision, ?int $link = null): void
    {
        $proposal->refresh();
        $options = [...$base, '--proposal' => $proposal->id, '--decision' => $decision, '--preview-digest' => $proposal->preview_digest];
        if ($link !== null) {
            $options['--link'] = $link;
        }
        [$exit, $output] = $this->artisan2('phr:ehi:review', $options);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString("proposal: {$proposal->id}", $output);
        $this->assertNoSourceValues($output);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: int, 1: string}
     */
    private function artisan2(string $command, array $parameters): array
    {
        $exit = Artisan::call($command, $parameters);

        return [$exit, Artisan::output()];
    }

    private function assertNoSourceValues(string $output): void
    {
        foreach ([EhiClinicalPackage::PATIENT_KEY, 'SYNTHETIC VACCINE', 'SYN-LOT', 'Synthetic final line', 'IMMUNE_ID', 'HNO_INFO', '.tsv', '.rtf', $this->ehiRoot] as $value) {
            self::assertStringNotContainsString($value, $output);
        }
    }

    /** @param  callable(): mixed  $review */
    private function assertRefused(string $reason, callable $review): void
    {
        try {
            $review();
            self::fail("Expected the review to be refused ({$reason}).");
        } catch (EhiReviewRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    private function immune(PhrEhiPackage $package, string $immuneId): PhrEhiProposal
    {
        return $this->proposal($package, EhiImmunizationMapping::NAME, ['IMMUNE_ID' => $immuneId]);
    }

    private function note(PhrEhiPackage $package, string $noteId): PhrEhiProposal
    {
        return $this->proposal($package, EhiDocumentMapping::NAME, ['NOTE_ID' => $noteId]);
    }

    /** @param  array<string, string>  $key */
    private function proposal(PhrEhiPackage $package, string $mapping, array $key): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', $mapping)
            ->where('subject_key', EhiMappingContext::keyDigest($key))->sole();
    }

    private function recordFor(PhrEhiSource $source, string $immuneId): PhrImmunization
    {
        return PhrImmunization::query()->where('import_source', EhiImmunizationMapping::importSource($source->id))
            ->where('external_id', EhiImmunizationMapping::externalId($immuneId))->sole();
    }

    private function documentFor(PhrEhiSource $source, string $externalId): PhrDocument
    {
        return PhrDocument::query()->where('import_source', EhiDocumentMapping::importSource($source->id))->where('external_id', $externalId)->sole();
    }

    private function blobFor(PhrEhiPackage $package, string $path): PhrEhiBlob
    {
        $member = PhrEhiPackageMember::query()->where('package_id', $package->id)->where('path', $path)->sole();

        return PhrEhiBlob::query()->findOrFail($member->blob_id);
    }

    private function recordDigest(PhrImmunization $record): string
    {
        return hash('sha256', (string) json_encode($record->getAttributes()));
    }

    /** @return array<string, int|string> */
    private function snapshot(): array
    {
        $counts = [];
        foreach ([...PhrNativeTableCompatibility::LATER_GENERATIONS['ehi_evidence'], 'phr_ehi_packages', 'phr_ehi_blobs', 'phr_immunizations', 'phr_documents'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        // A rewritten proposal would move its updated_at.
        $counts['proposals_last_updated'] = (string) DB::table('phr_ehi_proposals')->max('updated_at');
        $counts['document_objects'] = count(Storage::disk(PhrDocument::STORAGE_DISK)->allFiles());

        return $counts;
    }

    /** @return array<string, int> */
    private function evidenceCounts(int $patientId): array
    {
        $counts = [];
        foreach (PhrNativeTableCompatibility::LATER_GENERATIONS['ehi_evidence'] as $table) {
            $counts[$table] = DB::table($table)->where('patient_id', $patientId)->count();
        }

        return $counts;
    }
}
