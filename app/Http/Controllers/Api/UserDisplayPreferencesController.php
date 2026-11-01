<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user display preferences. They change how the interface renders and never
 * the stored data, so "force lowercase" lives here and not on any record.
 */
final readonly class UserDisplayPreferencesController
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->response($user);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['force_lowercase' => ['required', 'boolean']]);
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['force_lowercase_display' => (bool) $validated['force_lowercase']])->save();

        return $this->response($user);
    }

    private function response(User $user): JsonResponse
    {
        return response()->json(['force_lowercase' => (bool) $user->force_lowercase_display]);
    }
}
