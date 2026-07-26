<?php

namespace App\Services;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\BedrockClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\Contracts\GenAiClient;

class UserAiModelCatalog
{
    /**
     * @return list<string>
     */
    public function listModels(string $provider, string $apiKey, string $region = 'us-east-1', string $sessionToken = ''): array
    {
        $client = $this->makeClient($provider, $apiKey, $region, $sessionToken);

        return $this->normalizeModelInfoList($client->listModels());
    }

    private function makeClient(string $provider, string $apiKey, string $region, string $sessionToken): GenAiClient
    {
        return match ($provider) {
            'gemini' => new GeminiClient(apiKey: $apiKey),
            'anthropic' => new AnthropicClient(apiKey: $apiKey),
            'bedrock' => new BedrockClient(apiKey: $apiKey, modelId: 'any', region: $region, sessionToken: $sessionToken),
            default => throw new \InvalidArgumentException("Unsupported provider: {$provider}"),
        };
    }

    private function normalizeGeminiModelId(string $modelName): string
    {
        return str_starts_with($modelName, 'models/') ? substr($modelName, 7) : $modelName;
    }

    /**
     * @return list<string>
     */
    private function normalizeModelInfoList(mixed $modelInfos): array
    {
        if (! is_iterable($modelInfos)) {
            return [];
        }

        $modelIds = [];
        foreach ($modelInfos as $modelInfo) {
            $modelId = null;
            if (is_object($modelInfo) && isset($modelInfo->id) && is_string($modelInfo->id)) {
                $modelId = $modelInfo->id;
            } elseif (is_array($modelInfo) && is_string($modelInfo['id'] ?? null)) {
                $modelId = $modelInfo['id'];
            }

            if ($modelId !== null) {
                $modelIds[] = $this->normalizeGeminiModelId($modelId);
            }
        }

        return $modelIds;
    }
}
