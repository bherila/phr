<?php

namespace App\Support\PHR\Validation;

use App\Services\PHR\Encounters\PhrEncounterLinks;
use App\Services\PHR\Encounters\PhrLinkSuggestions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The query a stay's link suggestions take (#450), the same on the visit page and for an
 * agent: a margin of 0 to 14 days, some link types, a limit, and a cursor, which pages one
 * list of one type.
 */
final class LinkSuggestionQuery
{
    /**
     * @return array{types: list<string>|null, margin_days: int, limit: int, list: string|null, cursor: string|null}
     */
    public static function validate(Request $request): array
    {
        $validated = $request->validate([
            'margin_days' => ['nullable', 'integer', 'between:0,'.PhrLinkSuggestions::MAX_MARGIN_DAYS],
            'types' => ['nullable', 'array', 'min:1', 'max:'.count(PhrEncounterLinks::types())],
            'types.*' => ['required', 'string', 'distinct', Rule::in(PhrEncounterLinks::types())],
            'list' => ['nullable', 'string', Rule::in(PhrLinkSuggestions::LISTS)],
            'limit' => ['nullable', 'integer', 'between:1,'.PhrLinkSuggestions::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        // Types in their fixed order, whatever order they were sent in.
        $types = isset($validated['types'])
            ? array_values(array_filter(PhrEncounterLinks::types(), static fn (string $type): bool => in_array($type, (array) $validated['types'], true)))
            : null;
        $cursor = isset($validated['cursor']) ? (string) $validated['cursor'] : null;
        if ($cursor !== null && ($types === null || count($types) !== 1 || ! isset($validated['list']))) {
            throw ValidationException::withMessages(['cursor' => 'A cursor pages one list of one type: send that type in types[] and the list it came from.']);
        }

        return [
            'types' => $types,
            'margin_days' => (int) ($validated['margin_days'] ?? 0),
            'limit' => (int) ($validated['limit'] ?? PhrLinkSuggestions::DEFAULT_LIMIT),
            'list' => isset($validated['list']) ? (string) $validated['list'] : null,
            'cursor' => $cursor,
        ];
    }
}
