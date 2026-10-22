<?php

namespace App\Http\Controllers\Api;

use App\Models\OAuthClientConsent;
use App\Support\AgentApi\OAuthCredentialRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

final readonly class UserConnectedAppsController
{
    public function __construct(private OAuthCredentialRevoker $revoker) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $userId = $request->user('web')->getAuthIdentifier();
        $clients = OAuthClientConsent::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->select('client_id')
            ->groupBy('client_id')
            ->orderBy('client_id')
            ->paginate(20, ['client_id'], 'page', $validated['page'] ?? 1);
        $clientIds = $clients->getCollection()->pluck('client_id');
        $names = Passport::client()->newQuery()
            ->whereIn('id', $clientIds)
            ->pluck('name', 'id');
        $consents = OAuthClientConsent::query()
            ->where('user_id', $userId)
            ->whereIn('client_id', $clientIds)
            ->whereNull('revoked_at')
            ->orderBy('resource')
            ->get(['client_id', 'resource', 'scopes', 'granted_at'])
            ->groupBy('client_id');

        $data = $clients->getCollection()->map(static fn (OAuthClientConsent $client): array => [
            'client_id' => $client->client_id,
            'name' => $names->get($client->client_id) ?? 'Unavailable application',
            'resources' => $consents->get($client->client_id, collect())
                ->map(static fn (OAuthClientConsent $consent): array => [
                    'resource' => $consent->resource,
                    'scopes' => $consent->scopes,
                    'granted_at' => $consent->granted_at->toIso8601String(),
                ])->values()->all(),
        ])->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
                'per_page' => $clients->perPage(),
                'total' => $clients->total(),
            ],
        ]);
    }

    public function destroy(Request $request, string $clientId): Response
    {
        $userId = $request->user('web')->getAuthIdentifier();
        abort_unless(OAuthClientConsent::query()
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->exists(), 404);

        $this->revoker->revokeClientConsent($userId, $clientId);

        return response()->noContent();
    }
}
