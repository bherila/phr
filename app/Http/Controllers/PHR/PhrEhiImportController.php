<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiProposalDecision;
use App\Models\PhrEhiUpload;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\Ehi\EhiBindingRefused;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiImportPresenter;
use App\Services\PHR\Import\Ehi\EhiProjectionRefused;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\EhiReviewRefused;
use App\Services\PHR\Import\Ehi\EhiUploadRefused;
use App\Services\PHR\Import\Ehi\EhiUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Epic EHI Tables import from the browser (issue #235, PR 1). Each action wraps
 * the application service its `phr:ehi:*` command uses, with the same access
 * rule: reading needs patient access, uploading, previewing and reviewing need
 * manage access, and binding needs ownership. Nothing is written for an archived
 * patient. Refusals answer with the service's fixed reason code.
 */
final class PhrEhiImportController extends Controller
{
    private const int PROPOSAL_PAGE = 100;

    private const int PROPOSAL_PAGE_MAX = 500;

    public function __construct(
        private readonly PhrPatientAccessService $access,
        private readonly EhiUploadService $uploads,
        private readonly EhiBindingService $binding,
        private readonly EhiProposalService $proposals,
        private readonly EhiImportPresenter $presenter,
    ) {}

    public function uploads(Request $request, int $patient): JsonResponse
    {
        $patientModel = $this->access->accessiblePatient($patient, $this->actor($request));
        $uploads = PhrEhiUpload::query()->where('patient_id', $patientModel->id)->latest('id')->limit(20)->get();

        return $this->privateJson(['uploads' => $uploads->map(fn (PhrEhiUpload $upload): array => $this->presenter->upload($upload))->values()]);
    }

    public function startUpload(Request $request, int $patient): JsonResponse
    {
        $validated = $request->validate(['file_size_bytes' => ['required', 'integer', 'min:1']]);
        $patientModel = $this->access->writablePatient($patient, $this->actor($request));
        try {
            $upload = $this->uploads->start($patientModel, $this->actor($request), (int) $validated['file_size_bytes']);
        } catch (EhiUploadRefused $refused) {
            return $this->refused($refused->reason, $refused->status());
        }

        return $this->privateJson(['upload' => $this->presenter->upload($upload)], 201);
    }

    public function showUpload(Request $request, int $patient, int $upload): JsonResponse
    {
        $patientModel = $this->access->accessiblePatient($patient, $this->actor($request));

        return $this->privateJson(['upload' => $this->presenter->upload($this->upload($patientModel, $upload))]);
    }

    public function appendChunk(Request $request, int $patient, int $upload): JsonResponse
    {
        $validated = $request->validate([
            'chunk' => ['required', 'file'],
            'offset' => ['required', 'integer', 'min:0'],
        ]);
        $patientModel = $this->access->writablePatient($patient, $this->actor($request));
        try {
            $updated = $this->uploads->appendChunk($this->upload($patientModel, $upload), $this->actor($request), $validated['chunk'], (int) $validated['offset']);
        } catch (EhiUploadRefused $refused) {
            return $this->refused($refused->reason, $refused->status());
        }

        return $this->privateJson(['upload' => $this->presenter->upload($updated)]);
    }

    public function queueIntake(Request $request, int $patient, int $upload): JsonResponse
    {
        $patientModel = $this->access->writablePatient($patient, $this->actor($request));
        try {
            $queued = $this->uploads->queueIntake($this->upload($patientModel, $upload), $this->actor($request));
        } catch (EhiUploadRefused $refused) {
            return $this->refused($refused->reason, $refused->status());
        }

        return $this->privateJson(['upload' => $this->presenter->upload($queued)], 202);
    }

    public function packages(Request $request, int $patient): JsonResponse
    {
        $patientModel = $this->access->accessiblePatient($patient, $this->actor($request));
        $packages = PhrEhiPackage::query()->where('patient_id', $patientModel->id)->latest('id')->limit(50)->get();

        return $this->privateJson(['packages' => $packages->map(fn (PhrEhiPackage $package): array => $this->presenter->package($package))->values()]);
    }

    public function showPackage(Request $request, int $patient, int $package): JsonResponse
    {
        $patientModel = $this->access->accessiblePatient($patient, $this->actor($request));

        return $this->privateJson(['package' => $this->presenter->package($this->package($patientModel, $package))]);
    }

    public function bindingSuggestions(Request $request, int $patient, int $package): JsonResponse
    {
        $patientModel = $this->access->accessiblePatient($patient, $this->actor($request));
        $packageModel = $this->package($patientModel, $package);

        return $this->privateJson([
            'package' => $this->presenter->package($packageModel),
            'suggestions' => $this->presenter->suggestions($packageModel, $this->binding->suggestions($packageModel)),
        ]);
    }

    public function bind(Request $request, int $patient, int $package): JsonResponse
    {
        $validated = $request->validate([
            'source_id' => ['nullable', 'integer', 'min:1', 'required_without:new_source_label', 'prohibits:new_source_label'],
            'new_source_label' => ['nullable', 'string', 'max:120', 'required_without:source_id'],
        ]);
        $actor = $this->actor($request);
        $patientModel = $this->access->ownedPatient($patient, $actor);
        $packageModel = $this->package($patientModel, $package);
        try {
            $this->uploads->ensureWritable($patientModel, $actor);
            $bound = $this->binding->bind(
                $packageModel,
                $patientModel,
                $actor,
                isset($validated['source_id']) ? (int) $validated['source_id'] : null,
                isset($validated['new_source_label']) ? (string) $validated['new_source_label'] : null,
            );
        } catch (EhiUploadRefused $refused) {
            return $this->refused($refused->reason, $refused->status());
        } catch (EhiBindingRefused $refused) {
            return $this->refused($refused->reason, 409);
        }

        return $this->privateJson(['package' => $this->presenter->package($bound)]);
    }

    /**
     * Project the package and persist its reviewable proposals, synchronously, as
     * `phr:ehi:preview` does. The preview runs under its own row, byte and time
     * budget, and an interrupted projection resumes on the next call.
     */
    public function preview(Request $request, int $patient, int $package): JsonResponse
    {
        $actor = $this->actor($request);
        $patientModel = $this->access->writablePatient($patient, $actor);
        $packageModel = $this->package($patientModel, $package);
        try {
            $this->uploads->ensureWritable($patientModel, $actor);
            $preview = $this->proposals->preview($packageModel, $patientModel, $actor);
        } catch (EhiUploadRefused $refused) {
            return $this->refused($refused->reason, $refused->status());
        } catch (EhiProjectionRefused $refused) {
            return $this->refused($refused->reason, 409);
        }

        return $this->privateJson($this->presenter->preview($preview));
    }

    /** Proposals of the package's completed preview, in id order, a page at a time. */
    public function proposals(Request $request, int $patient, int $package): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in([
                PhrEhiProposal::STATUS_PENDING,
                PhrEhiProposal::STATUS_ACCEPTED,
                PhrEhiProposal::STATUS_REJECTED,
                PhrEhiProposal::STATUS_RETAINED,
                PhrEhiProposal::STATUS_LINKED,
                PhrEhiProposal::STATUS_SUPERSEDED,
            ])],
            'after_id' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::PROPOSAL_PAGE_MAX],
        ]);
        $patientModel = $this->access->accessiblePatient($patient, $this->actor($request));
        $packageModel = $this->package($patientModel, $package);
        $limit = (int) ($validated['limit'] ?? self::PROPOSAL_PAGE);

        $query = PhrEhiProposal::query()
            ->where('patient_id', $patientModel->id)
            ->where('package_id', $packageModel->id)
            ->where('id', '>', (int) ($validated['after_id'] ?? 0))
            ->tap(static fn ($query) => PhrEhiProposal::constrainToCompletedPreview($query->getQuery()))
            ->orderBy('id')
            ->limit($limit + 1);
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        $page = $query->get();
        $more = $page->count() > $limit;
        $page = $page->take($limit);

        return $this->privateJson([
            'preview_complete' => $packageModel->previewCompleted(),
            'proposals' => $page->map(fn (PhrEhiProposal $proposal): array => $this->presenter->proposal(
                $proposal,
                $proposal->status === PhrEhiProposal::STATUS_PENDING && $packageModel->completedPreviewIncludes($proposal),
            ))->values(),
            'next_after_id' => $more ? $page->last()?->id : null,
        ]);
    }

    /**
     * Record one review decision. It takes effect immediately; there is no batch
     * apply. The service rechecks manage access under the patient lock.
     */
    public function review(Request $request, int $patient, int $proposal): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(EhiProposalService::DECISIONS)],
            'preview_digest' => ['required', 'string', 'max:128'],
            'link_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $actor = $this->actor($request);
        $patientModel = $this->access->writablePatient($patient, $actor);
        $proposalModel = PhrEhiProposal::query()->where('patient_id', $patientModel->id)->findOrFail($proposal);
        try {
            $this->uploads->ensureWritable($patientModel, $actor);
            $decided = $this->proposals->review(
                $proposalModel,
                (string) $validated['decision'],
                $actor,
                (string) $validated['preview_digest'],
                isset($validated['link_id']) ? (int) $validated['link_id'] : null,
            );
        } catch (EhiUploadRefused $refused) {
            return $this->refused($refused->reason, $refused->status());
        } catch (EhiReviewRefused $refused) {
            return $this->refused($refused->reason, $refused->status());
        }

        $last = PhrEhiProposalDecision::query()->where('proposal_id', $decided->id)->orderByDesc('id')->first();
        $record = match (true) {
            $last?->linked_immunization_id !== null => ['table' => 'phr_immunizations', 'id' => (int) $last->linked_immunization_id],
            $last?->linked_document_id !== null => ['table' => 'phr_documents', 'id' => (int) $last->linked_document_id],
            default => null,
        };

        return $this->privateJson(['proposal' => $this->presenter->proposal($decided, false), 'record' => $record]);
    }

    private function actor(Request $request): int
    {
        return (int) $request->user()?->id;
    }

    private function upload(PhrPatient $patient, int $upload): PhrEhiUpload
    {
        return PhrEhiUpload::query()->where('patient_id', $patient->id)->findOrFail($upload);
    }

    private function package(PhrPatient $patient, int $package): PhrEhiPackage
    {
        return PhrEhiPackage::query()->where('patient_id', $patient->id)->findOrFail($package);
    }

    private function refused(string $reason, int $status): JsonResponse
    {
        return $this->privateJson(['error' => $reason], $status);
    }

    /** @param array<string, mixed> $payload */
    private function privateJson(array $payload, int $status = 200): JsonResponse
    {
        $response = response()->json($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
