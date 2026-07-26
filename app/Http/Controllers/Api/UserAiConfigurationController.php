<?php

namespace App\Http\Controllers\Api;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Support\GenAiCredentialErrorClassifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserAiConfigurationRequest;
use App\Models\UserAiConfiguration;
use App\Services\UserAiModelCatalog;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserAiConfigurationController extends Controller
{
    public function __construct(private readonly UserAiModelCatalog $modelCatalog) {}

    public function index(): JsonResponse
    {
        $configs = Auth::user()
            ->aiConfigurations()
            ->orderByDesc('is_active')
            ->orderBy('created_at')
            ->get();

        $configIds = $configs->pluck('id');

        /** @var array<int, array{input_tokens: int, output_tokens: int}> $thisMonthUsage */
        $thisMonthUsage = GenAiImportJob::whereIn('ai_configuration_id', $configIds)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->groupBy('ai_configuration_id')
            ->selectRaw('ai_configuration_id, COALESCE(SUM(input_tokens), 0) as total_input, COALESCE(SUM(output_tokens), 0) as total_output')
            ->get()
            ->keyBy('ai_configuration_id')
            ->map(fn (GenAiImportJob $row): array => [
                'input_tokens' => (int) $row->getAttribute('total_input'),
                'output_tokens' => (int) $row->getAttribute('total_output'),
            ])
            ->all();

        /** @var array<int, array{input_tokens: int, output_tokens: int}> $totalUsage */
        $totalUsage = GenAiImportJob::whereIn('ai_configuration_id', $configIds)
            ->groupBy('ai_configuration_id')
            ->selectRaw('ai_configuration_id, COALESCE(SUM(input_tokens), 0) as total_input, COALESCE(SUM(output_tokens), 0) as total_output')
            ->get()
            ->keyBy('ai_configuration_id')
            ->map(fn (GenAiImportJob $row): array => [
                'input_tokens' => (int) $row->getAttribute('total_input'),
                'output_tokens' => (int) $row->getAttribute('total_output'),
            ])
            ->all();

        $result = $configs->map(function (UserAiConfiguration $c) use ($thisMonthUsage, $totalUsage) {
            $usage = [
                'this_month' => [
                    'input_tokens' => $thisMonthUsage[$c->id]['input_tokens'] ?? 0,
                    'output_tokens' => $thisMonthUsage[$c->id]['output_tokens'] ?? 0,
                ],
                'total' => [
                    'input_tokens' => $totalUsage[$c->id]['input_tokens'] ?? 0,
                    'output_tokens' => $totalUsage[$c->id]['output_tokens'] ?? 0,
                ],
            ];

            return $c->toApiArray($usage);
        });

        return response()->json($result);
    }

    public function store(UserAiConfigurationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = Auth::user();
        $models = $this->validateCredentials(
            provider: $data['provider'],
            apiKey: $data['api_key'],
            region: $data['region'] ?? 'us-east-1',
            sessionToken: $data['session_token'] ?? '',
        );
        $model = $data['model'] ?? $models[0] ?? null;

        if (! $model) {
            return response()->json([
                'error' => 'No usable models were found for these API credentials.',
            ], 422);
        }

        $config = DB::transaction(function () use ($data, $model, $user) {
            // Lock existing configs to prevent a race between two concurrent first-config creates.
            $existingCount = $user->aiConfigurations()->lockForUpdate()->count();

            $config = $user->aiConfigurations()->create([
                'name' => $data['name'],
                'provider' => $data['provider'],
                'api_key' => $data['api_key'],
                'region' => $data['region'] ?? null,
                'session_token' => $data['session_token'] ?? null,
                'model' => $model,
                'is_active' => $existingCount === 0,
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            return $config;
        });

        return response()->json([
            ...$config->toApiArray(),
            'available_models' => $models,
        ], 201);
    }

    public function update(UserAiConfigurationRequest $request, int $id): JsonResponse
    {
        $config = $this->findOwned($id);
        $data = $request->validated();

        if ($data['provider'] !== $config->provider) {
            return response()->json([
                'error' => 'Provider cannot be changed after an API key configuration is created.',
            ], 422);
        }

        $models = null;
        if (! empty($data['api_key'])) {
            $models = $this->validateCredentials(
                provider: $data['provider'],
                apiKey: $data['api_key'],
                region: $data['region'] ?? 'us-east-1',
                sessionToken: $data['session_token'] ?? '',
            );

            if ($models === []) {
                return response()->json([
                    'error' => 'No usable models were found for these API credentials.',
                ], 422);
            }
        }

        $model = $data['model'] ?? $config->model;
        if ($models !== null && ! in_array($model, $models, true)) {
            $model = $models[0];
        }

        $update = [
            'name' => $data['name'],
            'region' => $data['region'] ?? null,
            'session_token' => $data['session_token'] ?? null,
            'model' => $model,
            'expires_at' => $data['expires_at'] ?? null,
        ];

        if (! empty($data['api_key'])) {
            $update['api_key'] = $data['api_key'];
            $update['api_key_invalid_at'] = null;
            $update['api_key_invalid_reason'] = null;
        }

        $config->update($update);

        return response()->json([
            ...$config->toApiArray(),
            'available_models' => $models,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $config = $this->findOwned($id);
        $wasActive = $config->is_active;

        DB::transaction(function () use ($config, $wasActive) {
            $config->delete();

            if ($wasActive) {
                // Promote the most recently created remaining config, if any
                Auth::user()->aiConfigurations()->latest()->first()?->update(['is_active' => true]);
            }
        });

        return response()->json(['success' => true]);
    }

    public function activate(int $id): JsonResponse
    {
        $config = $this->findOwned($id);

        if ($config->hasInvalidApiKey()) {
            return response()->json([
                'error' => 'This API key has been marked invalid. Edit the configuration with a valid key before activating it.',
            ], 422);
        }

        if ($config->isExpired()) {
            return response()->json([
                'error' => 'This API key has expired. Edit the configuration before activating it.',
            ], 422);
        }

        DB::transaction(function () use ($config) {
            // Lock all of the user's configs so concurrent activate calls are serialized
            // (the partial unique index would otherwise reject the loser with a generic 500).
            Auth::user()->aiConfigurations()->lockForUpdate()->get();
            Auth::user()->aiConfigurations()->update(['is_active' => false]);
            $config->update(['is_active' => true]);
        });

        return response()->json($config->fresh()->toApiArray());
    }

    private function findOwned(int $id): UserAiConfiguration
    {
        /** @var UserAiConfiguration */
        return Auth::user()->aiConfigurations()->findOrFail($id);
    }

    /**
     * @return list<string>
     */
    private function validateCredentials(string $provider, string $apiKey, string $region, string $sessionToken): array
    {
        try {
            return $this->modelCatalog->listModels($provider, $apiKey, $region, $sessionToken);
        } catch (GenAiFatalException $e) {
            Log::warning('Failed to validate AI configuration credentials', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            if (GenAiCredentialErrorClassifier::isInvalidCredential($provider, $e)) {
                abort(response()->json(['error' => 'Invalid API credentials.'], 422));
            }
        } catch (\Exception $e) {
            Log::warning('Failed to validate AI configuration credentials', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
        }

        abort(response()->json([
            'error' => 'Failed to validate API credentials. Please check your credentials and try again.',
        ], 422));
    }
}
