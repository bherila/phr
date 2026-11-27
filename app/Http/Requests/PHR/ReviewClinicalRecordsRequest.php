<?php

namespace App\Http\Requests\PHR;

use App\Services\PHR\Review\ClinicalReviewDecisions;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** @phpstan-import-type ReviewItem from ClinicalReviewDecisions */
final class ReviewClinicalRecordsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'review_status' => ['required', 'string', Rule::in(PhrReviewStatus::DECISIONS)],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.ClinicalReviewDecisions::BATCH_LIMIT],
            'items.*' => ['required', 'array:segment,id,version'],
            'items.*.segment' => ['required', 'string', Rule::in(array_keys(ClinicalReviewDecisions::models()))],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'items.*.version' => ['required', 'string', 'size:64'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $seen = [];
            foreach ($this->items() as $index => $item) {
                $key = $item['segment'].':'.$item['id'];
                if (isset($seen[$key])) {
                    $validator->errors()->add("items.{$index}.id", 'A record may be reviewed only once per batch.');
                }
                $seen[$key] = true;
            }
        }];
    }

    /** @return list<ReviewItem> */
    public function items(): array
    {
        /** @var list<array{segment: string, id: int|string, version: string}> $items */
        $items = $this->validated('items');

        return array_map(static fn (array $item): array => [...$item, 'id' => (int) $item['id']], $items);
    }

    public function reviewStatus(): string
    {
        return (string) $this->validated('review_status');
    }
}
