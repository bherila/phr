<?php

namespace App\Http\Requests\AgentApi;

use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bounded lookup of a client's own external IDs.
 *
 * The batch is deliberately capped. Resolution is the first step of any sync
 * pass, so an uncapped body would let one request scan an unbounded slice of a
 * patient's records on a route that is cheaper to call than a real read.
 */
class ResolveClinicalRecordsRequest extends FormRequest
{
    public const int MAX_EXTERNAL_IDS = 200;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $definition = AgentClinicalResourceCatalog::definition((string) $this->route('resource'));
        abort_unless(isset($definition['write_rules']), 404);

        return [
            'external_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_EXTERNAL_IDS],
            // Same shape the upsert accepts, so an ID that could be written can
            // always be resolved. Control characters stay out of log lines.
            'external_ids.*' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[^\p{C}]+$/u'],
        ];
    }

    /**
     * Request order is preserved so a caller can line the response up with its
     * own list. Duplicates collapse rather than 422, because a client batching
     * from several sources should not have to pre-deduplicate.
     *
     * @return list<string>
     */
    public function externalIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->validated('external_ids');

        return array_values(array_unique($ids));
    }
}
