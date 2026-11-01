<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\UpdateStorageSettingsRequest;
use App\Models\User;
use App\Services\PHR\Storage\BlobStorageQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StorageSettingsController extends Controller
{
    public function __construct(private readonly BlobStorageQuota $quota) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);

        return response()->json([...$this->quota->settings(), 'used_bytes' => $this->quota->usedBytes(), 'usage_ttl_seconds' => BlobStorageQuota::USAGE_TTL_SECONDS]);
    }

    public function update(UpdateStorageSettingsRequest $request): JsonResponse
    {
        DB::table('phr_storage_settings')->updateOrInsert(['id' => 1], $request->validated());

        return $this->show($request);
    }

    public function users(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        $search = mb_substr((string) $request->query('search', ''), 0, 100);

        return response()->json(['users' => User::query()->select(['id', 'name', 'email'])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->orderBy('id')->limit(25)->get()]);
    }

    public function user(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        $override = DB::table('phr_user_storage_limits')->where('user_id', $user->id)->first();

        return response()->json(['user_id' => $user->id, 'limit_bytes' => isset($override->limit_bytes) ? (int) $override->limit_bytes : null,
            'unlimited' => (bool) ($override->unlimited ?? false), 'effective_limit_bytes' => $this->quota->userLimit((int) $user->id),
            'used_bytes' => $this->quota->usedBytes((int) $user->id)]);
    }

    public function updateUser(UpdateStorageSettingsRequest $request, User $user): JsonResponse
    {
        DB::table('phr_user_storage_limits')->updateOrInsert(['user_id' => $user->id], $request->validated());

        return $this->user($request, $user);
    }
}
