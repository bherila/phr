<?php

namespace Tests\Contracts;

use App\Jobs\PHR\IntakeEhiUploadJob;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPreviewRun;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiUpload;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\Import\Ehi\EhiPreviewQueue;
use App\Services\PHR\Import\Ehi\EhiUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\EhiSyntheticPackage;
use Tests\Support\HttpContractFixtures;

/** Actual routes with a tiny intake/preview journey and explicitly prepared wire states. */
trait EhiHttpContractScenarios
{
    private const string EHI_PRIVATE = 'SYNTHETIC_CONTRACT_PRIVATE_VALUE';

    private function ehiScenarios(HttpContractFixtures $writer): void
    {
        $realQueue = Queue::getFacadeRoot();
        Queue::fake();
        config(['phr.ehi_mappings' => []]);
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $base = '/api/phr/patients/'.$patient->id.'/ehi';
        $viewer = $this->createUser();
        $outsider = $this->createUser();
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $viewer->id, 'access_level' => 'viewer',
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);
        $this->ehiCapture($writer, 'ehi-uploads-empty', $this->actingAs($owner)->getJson($base.'/uploads'));
        $writer->assertHttp('ehi-viewer-upload', $this->actingAs($viewer)->postJson($base.'/uploads', ['file_size_bytes' => 4]));
        $archive = tempnam(sys_get_temp_dir(), 'phr-contract-ehi-');
        HttpContractFixtures::require(is_string($archive), 'ehi-upload-start', 'archive');
        try {
            EhiSyntheticPackage::standard()->writeZip($archive);
            $response = $this->actingAs($owner)->postJson($base.'/uploads', ['file_size_bytes' => filesize($archive)]);
            $this->ehiCapture($writer, 'ehi-upload-start', $response);
            $id = $this->body($response)->upload->id;
            $response = $this->actingAs($owner)->post($base.'/uploads/'.$id.'/chunks', [
                'chunk' => new UploadedFile($archive, 'synthetic-ehi.zip', 'application/zip', null, true), 'offset' => 0,
            ], ['Accept' => 'application/json']);
            $this->ehiCapture($writer, 'ehi-upload-chunk', $response);
            HttpContractFixtures::require($this->body($response)->upload->uploaded_bytes === filesize($archive), 'ehi-upload-chunk', 'counts');
            $response = $this->actingAs($owner)->postJson($base.'/uploads/'.$id.'/intake');
            $this->ehiCapture($writer, 'ehi-upload-queued', $response);
            HttpContractFixtures::require($this->body($response)->upload->status === 'queued', 'ehi-upload-queued', 'state');
            $upload = PhrEhiUpload::query()->findOrFail($id);
            // A prepared in-flight state exercises polling, without claiming a concurrent worker observation.
            $upload->update(['status' => 'processing']);
            $this->ehiCapture($writer, 'ehi-upload-processing', $this->actingAs($owner)->getJson($base.'/uploads/'.$id));
            $upload->update(['status' => 'queued']);
            (new IntakeEhiUploadJob($id))->handle(app(EhiUploadService::class));
            $response = $this->actingAs($owner)->getJson($base.'/uploads/'.$id);
            $this->ehiCapture($writer, 'ehi-upload-completed', $response);
            HttpContractFixtures::require($this->body($response)->upload->status === 'completed', 'ehi-upload-completed', 'state');
            $package = PhrEhiPackage::query()->findOrFail($upload->refresh()->package_id);
            foreach (['uploading', 'queued', 'processing', 'rejected', 'failed'] as $state) {
                $snapshot = PhrEhiUpload::query()->create([
                    'patient_id' => $patient->id, 'actor_user_id' => $owner->id, 'storage_disk' => 'phr_documents',
                    'storage_path' => self::EHI_PRIVATE, 'multipart_upload_id' => self::EHI_PRIVATE,
                    'file_size_bytes' => 4, 'uploaded_bytes' => $state === 'uploading' ? 0 : 4,
                    'transport' => 'chunks', 'status' => $state,
                    'failure_reason' => match ($state) {
                        'rejected' => 'unrecognized_export', 'failed' => 'unexpected', default => null
                    },
                    'result_json' => ['raw_exception' => self::EHI_PRIVATE, 'source_content' => self::EHI_PRIVATE],
                    'expires_at' => now()->addDay(), 'completed_at' => in_array($state, ['rejected', 'failed'], true) ? now() : null,
                ]);
                if (in_array($state, ['rejected', 'failed'], true)) {
                    $this->ehiCapture($writer, 'ehi-upload-'.$state, $this->actingAs($owner)->getJson($base.'/uploads/'.$snapshot->id));
                }
            }
            $response = $this->actingAs($owner)->getJson($base.'/uploads');
            $this->ehiCapture($writer, 'ehi-uploads-all-states', $response);
            $states = array_map(static fn (object $item): string => $item->status, $this->body($response)->uploads);
            sort($states);
            HttpContractFixtures::require($states === ['completed', 'failed', 'processing', 'queued', 'rejected', 'uploading'], 'ehi-uploads-all-states', 'state');
            $empty = PhrEhiPackage::query()->create([
                'patient_id' => $patient->id, 'created_by_user_id' => $owner->id,
                'manifest_digest' => str_repeat('f', 64), 'intake_kind' => 'zip',
                'member_count' => 0, 'total_bytes' => 0, 'intake_count' => 0,
                'detected_source_patient_key' => self::EHI_PRIVATE,
                'checkpoint_json' => ['raw_exception' => self::EHI_PRIVATE, 'member_path' => self::EHI_PRIVATE],
            ]);
            $this->ehiCapture($writer, 'ehi-package-empty', $this->actingAs($owner)->getJson($base.'/packages/'.$empty->id));
            $this->ehiCapture($writer, 'ehi-packages-list', $this->actingAs($owner)->getJson($base.'/packages'));
            $route = $base.'/packages/'.$package->id;
            $writer->assertHttp('ehi-outsider-package', $this->actingAs($outsider)->getJson($route));
            $writer->assertHttp('ehi-viewer-preview', $this->actingAs($viewer)->postJson($route.'/preview'));
            $this->actingAs($owner)->postJson($route.'/binding', ['new_source_label' => 'Synthetic contract source'])->assertOk();
            $response = $this->actingAs($owner)->postJson($route.'/preview');
            $this->ehiCapture($writer, 'ehi-preview-queued', $response);
            $run = PhrEhiPreviewRun::query()->findOrFail($this->body($response)->package->preview_run->id);
            HttpContractFixtures::require($run->status === 'queued', 'ehi-preview-queued', 'state');
            $run->update(['status' => 'running', 'started_at' => now()]);
            $this->ehiCapture($writer, 'ehi-preview-running', $this->actingAs($owner)->getJson($route));
            $run->update(['status' => 'queued']);
            app(EhiPreviewQueue::class)->run($run->id);
            $response = $this->actingAs($owner)->getJson($route);
            $this->ehiCapture($writer, 'ehi-preview-completed', $response);
            HttpContractFixtures::require($this->body($response)->package->preview_run->status === 'completed'
                && $this->body($response)->package->preview->state === 'complete', 'ehi-preview-completed', 'state');
            $this->ehiCapture($writer, 'ehi-pending-empty', $this->actingAs($owner)->getJson($base.'/pending'));
            $proposal = PhrEhiProposal::query()->create([
                'patient_id' => $patient->id, 'package_id' => $package->id, 'mapping' => 'synthetic_contract_mapping',
                'mapping_version' => 'contract-1', 'subject_key' => 'contract-subject', 'revision_digest' => str_repeat('a', 64),
                'kind' => 'retain', 'status' => 'pending', 'proposed_json' => [], 'before_json' => [],
                'candidate_ids_json' => [], 'preview_digest' => str_repeat('b', 64),
                'preview_generation' => $package->refresh()->preview_generation_completed,
            ]);
            $response = $this->actingAs($owner)->getJson($route.'/proposals');
            $this->ehiCapture($writer, 'ehi-proposals-empty-map', $response);
            HttpContractFixtures::require(count($this->body($response)->proposals) === 1 && $this->body($response)->proposals[0]->reviewable,
                'ehi-proposals-empty-map', 'state');
            $this->ehiCapture($writer, 'ehi-pending-completed', $this->actingAs($owner)->getJson($base.'/pending'));
            $package->forceFill(['preview_generation_started' => $package->preview_generation_completed + 1])->save();
            $response = $this->actingAs($owner)->getJson($route);
            $this->ehiCapture($writer, 'ehi-preview-incomplete', $response);
            HttpContractFixtures::require($this->body($response)->package->preview->state === 'incomplete'
                && $this->body($response)->package->proposals->pending_unreviewable === 1, 'ehi-preview-incomplete', 'state');
            $response = $this->actingAs($owner)->getJson($route.'/proposals');
            $this->ehiCapture($writer, 'ehi-proposals-incomplete', $response);
            HttpContractFixtures::require($this->body($response)->proposals === [] && ! $this->body($response)->preview_complete, 'ehi-proposals-incomplete', 'state');
            $response = $this->actingAs($owner)->getJson($base.'/pending');
            $this->ehiCapture($writer, 'ehi-pending-incomplete', $response);
            HttpContractFixtures::require(get_object_vars($this->body($response)->pending) === [], 'ehi-pending-incomplete', 'state');
            $writer->assertHttp('ehi-incomplete-review', $this->actingAs($owner)->postJson($base.'/proposals/'.$proposal->id.'/review', [
                'decision' => 'retain_evidence', 'preview_digest' => $proposal->preview_digest,
            ]));
            $run->update(['result_json' => ['counts' => ['synthetic_contract_mapping' => []], 'statuses' => [], 'findings' => []]]);
            $this->ehiCapture($writer, 'ehi-preview-empty-maps', $this->actingAs($owner)->getJson($route));
            $variants = [
                'time' => ['failure_details' => ['resource_limit' => 'time']],
                'capacity' => ['failure_details' => ['resource_limit' => 'capacity']],
                'null' => null,
                'unknown-string' => ['failure_details' => ['resource_limit' => self::EHI_PRIVATE, 'raw_exception' => self::EHI_PRIVATE]],
                'unknown-shape' => ['failure_details' => ['resource_limit' => ['raw_exception' => self::EHI_PRIVATE]]],
                'known-extra' => ['failure_details' => ['resource_limit' => 'time', 'raw_exception' => self::EHI_PRIVATE], 'source_content' => self::EHI_PRIVATE],
                'wrong-reason' => ['failure_details' => ['resource_limit' => 'time']],
            ];
            foreach ($variants as $variant => $result) {
                $run->update(['status' => 'failed', 'failure_reason' => $variant === 'wrong-reason' ? 'unexpected' : 'resource_limit',
                    'result_json' => $result, 'completed_at' => now()]);
                $response = $this->actingAs($owner)->getJson($route);
                $id = 'ehi-preview-resource-'.$variant;
                $this->ehiCapture($writer, $id, $response);
                $detail = $this->body($response)->package->preview_run->failure_details;
                $expected = match ($variant) {
                    'time', 'known-extra' => 'time', 'capacity' => 'capacity', default => null
                };
                HttpContractFixtures::require($expected === null ? $detail === null
                    : is_object($detail) && get_object_vars($detail) === ['resource_limit' => $expected], $id, 'private_field');
            }
        } finally {
            @unlink($archive);
            Queue::swap($realQueue);
        }
    }

    /** Raw privacy is asserted independently of the frontend schema's stripping/catch behavior.
     * @param  TestResponse<Response>  $response
     */
    private function ehiCapture(HttpContractFixtures $writer, string $id, TestResponse $response): void
    {
        $writer->capture($id, $response);
        $raw = (string) $response->getContent();
        foreach ([self::EHI_PRIVATE, EhiSyntheticPackage::MARKER, EhiSyntheticPackage::PATIENT_KEY] as $secret) {
            HttpContractFixtures::require(! str_contains($raw, $secret), $id, 'private_field');
        }
        foreach (['storage_path', 'multipart_upload_id', 'result_json', 'checkpoint_json', 'raw_exception', 'source_content', 'member_path', 'lease_token'] as $field) {
            HttpContractFixtures::require(! str_contains($raw, '"'.$field.'":'), $id, 'private_field');
        }
    }
}
