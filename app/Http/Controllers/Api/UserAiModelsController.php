<?php

namespace App\Http\Controllers\Api;

use App\GenAiProcessor\Support\GenAiCredentialErrorClassifier;
use App\Http\Controllers\Controller;
use App\Models\UserAiConfiguration;
use App\Services\UserAiModelCatalog;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UserAiModelsController extends Controller
{
    public function __construct(private readonly UserAiModelCatalog $modelCatalog) {}

    public function fetch(Request $request): JsonResponse
    {
        $request->validate([
            'provider' => ['required', 'string', 'in:gemini,anthropic,bedrock'],
            'api_key' => ['nullable', 'string'],
            'config_id' => ['nullable', 'integer'],
            'region' => ['nullable', 'string'],
            'session_token' => ['nullable', 'string'],
        ]);

        $provider = (string) $request->input('provider');
        $apiKey = $request->input('api_key');
        $apiKey = is_string($apiKey) ? $apiKey : null;
        /** @var UserAiConfiguration|null $config */
        $config = null;

        // For edit flows, accept a config_id and reuse the saved key rather than
        // requiring the user to re-enter it.
        if (! $apiKey && $request->filled('config_id')) {
            /** @var UserAiConfiguration|null $config */
            $config = Auth::user()->aiConfigurations()->find($request->input('config_id'));
            if ($config) {
                $apiKey = $config->api_key;
            }
        }

        if (! $apiKey) {
            return response()->json(['error' => 'An API key is required to fetch models.'], 422);
        }

        $region = is_string($request->input('region')) ? $request->input('region') : 'us-east-1';
        $sessionToken = is_string($request->input('session_token')) ? $request->input('session_token') : '';

        try {
            $models = $this->modelCatalog->listModels($provider, $apiKey, $region, $sessionToken);

            if ($config?->hasInvalidApiKey()) {
                $config->clearApiKeyInvalid();
            }

            return response()->json(['models' => $models]);
        } catch (GenAiFatalException $e) {
            Log::warning('Failed to fetch AI models', ['provider' => $provider, 'error' => $e->getMessage()]);

            if (GenAiCredentialErrorClassifier::isInvalidCredential($provider, $e)) {
                return response()->json(['error' => 'Invalid API credentials.'], 422);
            }

            return response()->json(['error' => 'Failed to fetch models. Please check your credentials and try again.'], 422);
        } catch (\Exception $e) {
            Log::warning('Failed to fetch AI models', ['provider' => $provider, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to fetch models. Please check your credentials and try again.'], 422);
        }
    }
}
