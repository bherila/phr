<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\PHR\DisplayPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final readonly class UserDisplayPreferencesController
{
    /** Saves what was sent over what is stored, so a change to one setting leaves the others. */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_format' => ['sometimes', 'string', Rule::in(array_keys(DisplayPreferences::DATE_FORMATS))],
            'hide_medication_list' => ['sometimes', 'boolean:strict'],
            'separate_columns' => ['sometimes', 'boolean:strict'],
            'hidden_fill_columns' => ['sometimes', 'array'],
            'hidden_fill_columns.*' => ['string', Rule::in(DisplayPreferences::FILL_COLUMNS)],
        ]);
        /** @var User $current */
        $current = $request->user();

        // Read and merged under a lock, so two quick saves of different settings both stick.
        $preferences = DB::transaction(function () use ($current, $validated): array {
            $user = User::query()->lockForUpdate()->findOrFail($current->getKey());
            $merged = DisplayPreferences::normalize([...$user->displayPreferences(), ...$validated]);
            $user->forceFill(['display_preferences' => $merged])->save();

            return $merged;
        });

        return response()->json(['display_preferences' => $preferences]);
    }
}
