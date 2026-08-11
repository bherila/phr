<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Session-protected device management for the paired-device config UI (built
 * later, alongside the PHR Config screen). Deliberately small: list and
 * revoke only. Never exposes token_hash — a device key row here is only ever
 * something a user recognizes and revokes, not something they read back.
 */
class UserDeviceController extends Controller
{
    public function index(): JsonResponse
    {
        $devices = Auth::user()
            ->deviceKeys()
            ->orderByDesc('created_at')
            ->get(['id', 'device_id', 'name', 'created_at', 'last_used_at', 'expires_at', 'revoked_at']);

        return response()->json($devices);
    }

    public function destroy(int $id): JsonResponse
    {
        $device = Auth::user()->deviceKeys()->findOrFail($id);
        $device->forceFill(['revoked_at' => now()])->save();

        return response()->json(['success' => true]);
    }
}
