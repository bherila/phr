<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\UpdateSinusSettingsRequest;
use App\Http\Resources\PHR\SinusSettingResource;
use App\Models\PhrSinusSetting;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sinus Sentinel detection settings, shared across a user's devices.
 *
 * Conflict resolution is last-write-wins on the client's `updated_at`. The
 * response always carries the *winning* document plus an `applied` flag, so a
 * device that loses the race adopts server state in the same round trip instead
 * of needing a second request.
 */
class SinusSettingsController extends Controller
{
    public function __construct(private PhrPatientAccessService $accessService) {}

    public function show(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        $setting = PhrSinusSetting::query()
            ->where('phr_patient_id', $resolvedPatient->id)
            ->first();

        return response()->json([
            'sinus_settings' => $setting === null
                ? null
                : (new SinusSettingResource($setting))->resolve(),
            'can_manage' => $this->accessService->canWrite($resolvedPatient, $userId),
        ]);
    }

    public function update(UpdateSinusSettingsRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $incomingSettings = PhrSinusSetting::filterSyncedKeys((array) $validated['settings']);
        $incomingUpdatedAt = Carbon::parse((string) $validated['updated_at']);
        $deviceId = isset($validated['device_id']) ? (string) $validated['device_id'] : null;

        $reconcile = fn (): array => DB::transaction(function () use (
            $resolvedPatient,
            $incomingSettings,
            $incomingUpdatedAt,
            $deviceId,
        ): array {
            // Lock the row so two devices flushing at once cannot both read the
            // old timestamp and both decide they won.
            $existing = PhrSinusSetting::query()
                ->where('phr_patient_id', $resolvedPatient->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->settings_updated_at >= $incomingUpdatedAt) {
                return ['setting' => $existing, 'applied' => false];
            }

            $setting = PhrSinusSetting::query()->updateOrCreate(
                ['phr_patient_id' => $resolvedPatient->id],
                [
                    'settings' => $incomingSettings,
                    'settings_updated_at' => $incomingUpdatedAt,
                    'received_at' => now(),
                    'updated_by_device' => $deviceId,
                ],
            );

            return ['setting' => $setting, 'applied' => true];
        });

        try {
            /** @var array{setting: PhrSinusSetting, applied: bool} $outcome */
            $outcome = $reconcile();
        } catch (UniqueConstraintViolationException) {
            // There is nothing to lock before the row exists, so two devices
            // racing the very first PUT can both take the create path. Retry
            // once: the row now exists, so the lock-and-compare above applies
            // and one of them correctly loses. Without this the loser gets a
            // 500, which fails its whole flush and trips the backoff.
            /** @var array{setting: PhrSinusSetting, applied: bool} $outcome */
            $outcome = $reconcile();
        }

        return response()->json([
            'applied' => $outcome['applied'],
            'sinus_settings' => (new SinusSettingResource($outcome['setting']))->resolve(),
        ]);
    }
}
