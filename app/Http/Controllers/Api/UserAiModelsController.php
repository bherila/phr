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
            'api_key' => ['nullable', 'string', 'max:500'],
            'config_id' => ['nullable', 'integer'],
            'region' => ['nullable', 'string', 'max:64', 'prohibited_unless:provider,bedrock'],
            'session_token' => ['nullable', 'string', 'max:4096', 'prohibited_unless:provider,bedrock', 'prohibited_if:clear_session_token,true'],
            'clear_session_token' => ['nullable', 'boolean', 'prohibited_unless:provider,bedrock'],
        ]);

        $provider = (string) $request->input('provider');
        $apiKey = $request->input('api_key');
        $apiKey = is_string($apiKey) ? $apiKey : null;
        $usesStoredApiKey = false;
        /** @var UserAiConfiguration|null $config */
        $config = null;

        // Edit flows reuse credentials without ever returning them to the browser.
        // A config id from another user remains indistinguishable from a missing row.
        if ($request->filled('config_id')) {
            /** @var UserAiConfiguration|null $config */
            $config = Auth::user()->aiConfigurations()->find($request->input('config_id'));

            if (! $config) {
                abort(404);
            }

            if ($config->provider !== $provider) {
                return response()->json(['error' => 'Provider does not match this configuration.'], 422);
            }

            if (! $apiKey) {
                $apiKey = $config->api_key;
                $usesStoredApiKey = true;
            }
        }

        if (! $apiKey) {
            return response()->json(['error' => 'An API key is required to fetch models.'], 422);
        }

        $regionInput = $request->input('region');
        $region = is_string($regionInput) && $regionInput !== '' ? $regionInput : 'us-east-1';
        if ((! is_string($regionInput) || $regionInput === '') && $config !== null && $config->region !== null) {
            $region = $config->region;
        }
        $sessionTokenInput = $request->input('session_token');
        $clearSessionToken = $request->boolean('clear_session_token');
        $sessionToken = is_string($sessionTokenInput) && $sessionTokenInput !== '' && ! $clearSessionToken ? $sessionTokenInput : '';
        if (! $clearSessionToken && (! is_string($sessionTokenInput) || $sessionTokenInput === '') && $config !== null) {
            $sessionToken = $config->session_token ?? '';
        }
        $usesStoredCredentials = $usesStoredApiKey
            && $config !== null
            && ! $clearSessionToken
            && $region === ($config->region ?? 'us-east-1')
            && $sessionToken === ($config->session_token ?? '');

        try {
            $models = $this->modelCatalog->listModels($provider, $apiKey, $region, $sessionToken);

            // A replacement key is not persisted until update succeeds, so validating
            // one must not clear the invalid marker on the still-saved credential.
            if ($usesStoredCredentials && $config->hasInvalidApiKey()) {
                $config->clearApiKeyInvalid();
            }

            return response()->json(['models' => $models]);
        } catch (GenAiFatalException $e) {
            Log::warning('Failed to fetch AI models', [
                'provider' => $provider,
                'exception' => $e::class,
            ]);

            if (GenAiCredentialErrorClassifier::isInvalidCredential($provider, $e)) {
                return response()->json(['error' => 'Invalid API credentials.'], 422);
            }

            return response()->json(['error' => 'Failed to fetch models. Please check your credentials and try again.'], 422);
        } catch (\Exception $e) {
            Log::warning('Failed to fetch AI models', [
                'provider' => $provider,
                'exception' => $e::class,
            ]);

            return response()->json(['error' => 'Failed to fetch models. Please check your credentials and try again.'], 422);
        }
    }
}
