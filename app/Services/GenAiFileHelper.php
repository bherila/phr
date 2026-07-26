<?php

namespace App\Services;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\ToolConfig;

class GenAiFileHelper
{
    public const ASSISTANT_PREFILL_RESPONSE_KEY = '_assistant_prefill';

    /**
     * Upload a file to the provider's File API if supported, then converse.
     * For providers without a File API (Anthropic, Bedrock), falls back to inline base64.
     *
     * @param  resource  $stream
     */
    public static function send(
        GenAiClient $client,
        mixed $stream,
        string $mimeType,
        string $name,
        string $prompt,
        ?ToolConfig $toolConfig = null,
        ?string $assistantPrefill = null,
    ): mixed {
        $fileUri = $client->uploadFile($stream, $mimeType, $name);

        if ($fileUri !== null) {
            try {
                return $client->converseWithFileRef($fileUri, $mimeType, $prompt, $toolConfig);
            } finally {
                $client->deleteFile($fileUri);
            }
        }

        // Provider has no File API — read stream into memory and send inline.
        if (stream_get_meta_data($stream)['seekable']) {
            rewind($stream);
        }

        $bytes = stream_get_contents($stream);

        if ($bytes === false) {
            throw new \RuntimeException('Failed to read file stream.');
        }

        $base64 = base64_encode($bytes);

        if ($assistantPrefill === null || $assistantPrefill === '') {
            return $client->converseWithInlineFile($base64, $mimeType, $prompt, '', $toolConfig);
        }

        $response = $client->converse('', [
            [
                'role' => 'user',
                'content' => [
                    ContentBlock::document($base64, $mimeType),
                    ContentBlock::text($prompt),
                ],
            ],
            [
                'role' => 'assistant',
                'content' => [
                    ContentBlock::text($assistantPrefill),
                ],
            ],
        ], $toolConfig);

        $response[self::ASSISTANT_PREFILL_RESPONSE_KEY] = $assistantPrefill;

        return $response;
    }

    /**
     * Check that the file size is within the provider's accepted limit.
     */
    public static function withinSizeLimit(GenAiClient $client, int $bytes): bool
    {
        return $bytes <= $client::maxFileBytes();
    }
}
