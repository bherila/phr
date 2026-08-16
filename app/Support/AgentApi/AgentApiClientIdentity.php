<?php

namespace App\Support\AgentApi;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;

final readonly class AgentApiClientIdentity
{
    private function __construct(public string $id) {}

    public static function fromRequest(Request $request): self
    {
        $token = $request->user('api')?->token();
        $attributes = $token instanceof AccessToken ? $token->toArray() : [];
        $clientId = $attributes['oauth_client_id'] ?? null;

        abort_unless(is_string($clientId) && Str::isUuid($clientId), 401);

        return new self($clientId);
    }

    /**
     * The OAuth client is the import source. Namespacing stable external IDs by
     * client prevents one authorized integration from overwriting another
     * integration's records, even when both choose the same external ID.
     */
    public function importSource(): string
    {
        return 'agent-client:'.$this->id;
    }
}
