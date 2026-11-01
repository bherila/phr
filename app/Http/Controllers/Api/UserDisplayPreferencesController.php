<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\PHR\DisplayPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final readonly class UserDisplayPreferencesController
{
    /** Saves what was sent over what is stored, so a change to one setting leaves the others. */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hide_medication_list' => ['sometimes', 'boolean:strict'],
            'hidden_fill_columns' => ['sometimes', 'array'],
            'hidden_fill_columns.*' => ['string', Rule::in(DisplayPreferences::FILL_COLUMNS)],
        ]);
        /** @var User $user */
        $user = $request->user();
        $preferences = DisplayPreferences::normalize([...$user->displayPreferences(), ...$validated]);
        $user->forceFill(['display_preferences' => $preferences])->save();

        return response()->json(['display_preferences' => $preferences]);
    }
}
