<?php

namespace App\Http\Requests\PHR;

use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Records a human's review decision on a clinical record.
 *
 * Only `confirmed` and `rejected` are accepted. `pending_review` is a state the
 * server assigns when an agent writes or edits a record; a reviewer cannot put
 * a record back into the queue by hand.
 */
class ReviewClinicalRecordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'review_status' => ['required', 'string', Rule::in(PhrReviewStatus::DECISIONS)],
        ];
    }

    public function reviewStatus(): string
    {
        return (string) $this->validated('review_status');
    }
}
