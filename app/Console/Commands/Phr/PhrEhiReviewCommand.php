<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrEhiProposal;
use App\Models\PhrEhiProposalDecision;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\EhiReviewRefused;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

#[Signature('phr:ehi:review {--patient= : PHR patient id} {--actor= : Acting user id} {--proposal= : EHI proposal id} {--decision= : accept, approve_update, link_existing, reject, or retain_evidence} {--preview-digest= : The proposal preview digest that was reviewed} {--link= : Existing record id, for link_existing}')]
#[Description('Record a review decision for one previewed EHI proposal')]
class PhrEhiReviewCommand extends BaseEhiCommand
{
    public function handle(PhrPatientAccessService $accessService, EhiProposalService $proposals): int
    {
        $patient = $this->writablePatient($accessService);
        $proposal = PhrEhiProposal::query()->where('patient_id', $patient->id)->find($this->intOptionRequired('proposal'));
        if ($proposal === null) {
            $this->error('--proposal does not name an EHI proposal of this patient.');

            return self::INVALID;
        }
        $decision = $this->optionalStringOption('decision');
        $digest = $this->optionalStringOption('preview-digest');
        if ($decision === null || $digest === null) {
            $this->error('--decision and --preview-digest are required.');

            return self::INVALID;
        }

        try {
            $decided = $proposals->review($proposal, $decision, $this->intOptionRequired('actor'), $digest, $this->optionalIntOption('link'));
        } catch (EhiReviewRefused $refused) {
            $this->line("refused: {$refused->reason}");

            return self::FAILURE;
        } catch (AuthorizationException|ModelNotFoundException $denied) {
            throw $denied;
        } catch (Throwable $failure) {
            // Never print the message: it could carry a source or clinical detail.
            $this->line('failed: unexpected ('.class_basename($failure).')');

            return self::FAILURE;
        }

        $this->line("proposal: {$decided->id}");
        $this->line("status: {$decided->status}");
        $last = PhrEhiProposalDecision::query()->where('proposal_id', $decided->id)->orderByDesc('id')->first();
        if ($last?->linked_immunization_id !== null) {
            $this->line("record: phr_immunizations {$last->linked_immunization_id}");
        } elseif ($last?->linked_document_id !== null) {
            $this->line("record: phr_documents {$last->linked_document_id}");
        }

        return self::SUCCESS;
    }
}
