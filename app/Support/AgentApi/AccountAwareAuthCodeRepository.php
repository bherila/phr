<?php

namespace App\Support\AgentApi;

use App\Models\User;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

class AccountAwareAuthCodeRepository extends AuthCodeRepository
{
    public function __construct(
        private OAuthExchangeAccountGuard $accountGuard,
        private OAuthDynamicClientDao $dynamicClients,
    ) {}

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $userId = $authCodeEntity->getUserIdentifier();
        $validatedGrant = $userId === null ? null : $this->accountGuard->validatedGrantFor($userId);
        // Authorization routes always pass through the account-state middleware.
        // Missing request-local state fails closed as an unusable code rather than
        // falling back to a race-prone user-table read here.
        $securityVersion = $validatedGrant['security_version'] ?? null;
        $resourceUri = OAuthResourceIndicator::fromRequest(request());
        $scopeIds = array_map(
            static fn ($scope): string => $scope->getIdentifier(),
            $authCodeEntity->getScopes(),
        );
        $resourceIsValid = ! in_array(AgentApiScopes::MCP_USE, $scopeIds, true)
            || $resourceUri === OAuthResourceIndicator::agentApi();
        $client = $this->dynamicClients->lockForAuthorization(
            $authCodeEntity->getClient()->getIdentifier(),
        );
        if ($client === null) {
            throw OAuthServerException::invalidGrant('The authorization grant is invalid.');
        }

        Passport::authCode()->forceFill([
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $userId,
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'scopes' => json_encode($authCodeEntity->getScopes()),
            'revoked' => ! $resourceIsValid,
            'oauth_security_version' => $securityVersion,
            'resource_uri' => $resourceUri,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
        ])->save();

        $this->dynamicClients->markAuthorized($client);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        // The token-exchange middleware holds this lock through issuance and
        // Passport's final revoke, preserving one-time authorization-code use.
        $authorizationCode = Passport::authCode()->newQuery()
            ->whereKey($codeId)
            ->where('revoked', false)
            ->lockForUpdate()
            ->first();

        if ($authorizationCode === null) {
            return true;
        }

        $storedResource = is_string($authorizationCode->resource_uri)
            ? $authorizationCode->resource_uri
            : null;
        $requestedResource = request()->exists('resource')
            ? OAuthResourceIndicator::canonicalize(request()->input('resource'))
            : $storedResource;
        if ($requestedResource !== $storedResource) {
            $authorizationCode->forceFill(['revoked' => true])->save();

            return true;
        }

        $user = User::query()->find($authorizationCode->user_id);

        if (! $user instanceof User || ! $user->canLogin()) {
            app(OAuthCredentialRevoker::class)->revokeForUserIdentifier($authorizationCode->user_id);

            return true;
        }

        if ($authorizationCode->oauth_security_version === null
            || (int) $authorizationCode->oauth_security_version !== (int) $user->oauth_security_version) {
            $authorizationCode->forceFill(['revoked' => true])->save();

            return true;
        }

        $this->accountGuard->recordValidatedGrant(
            $authorizationCode->user_id,
            (int) $authorizationCode->oauth_security_version,
            null,
            $storedResource,
        );

        return false;
    }
}
