<?php

namespace App\Support\AgentApi;

use App\Models\User;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
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
        $resourceUri = OAuthResourceIndicator::validatedFor(request());
        $scopeIds = array_map(
            static fn ($scope): string => $scope->getIdentifier(),
            $authCodeEntity->getScopes(),
        );
        // A code is usable only for a configured resource whose scope ceiling
        // admits every granted scope (an MCP connection scope never reaches a
        // REST code), and a scope that needs a resource never goes without one.
        $resourceIsValid = $resourceUri === null
            ? ! OAuthResourceIndicator::scopesRequireResource($scopeIds)
            : OAuthResourceIndicator::scopesAllowedFor($resourceUri, $scopeIds);
        $client = $this->dynamicClients->lockForAuthorization(
            $authCodeEntity->getClient()->getIdentifier(),
        );
        if ($client === null) {
            throw OAuthServerException::invalidGrant('The authorization grant is invalid.');
        }

        $model = Passport::authCode();
        $model->forceFill([
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $userId,
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'scopes' => json_encode($authCodeEntity->getScopes()),
            'revoked' => ! $resourceIsValid,
            'oauth_security_version' => $securityVersion,
            'resource_uri' => $resourceUri,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
            ...ProviderIdentityStamp::forIssue($model, $userId),
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
        // The exchange names the code's own resource, or omits it and means the
        // REST resource. A mismatch is refused without consuming the code, so a
        // client can retry with the resource it was granted for.
        $requestedResource = OAuthResourceIndicator::requestNamesResource(request())
            ? OAuthResourceIndicator::requestResource(request())
            : $storedResource;
        if ($requestedResource !== $storedResource) {
            return true;
        }
        // A scope ceiling tightened since consent applies to the token the code mints.
        if ($storedResource !== null && ! OAuthResourceIndicator::scopesAllowedFor(
            $storedResource,
            OAuthResourceIndicator::scopeIdentifiers($authorizationCode->scopes),
        )) {
            return true;
        }

        $user = User::query()->find($authorizationCode->user_id);

        if (! $user instanceof User || ! $user->mayHoldOAuthCredentials()) {
            app(OAuthCredentialRevoker::class)->revokeForUserIdentifier($authorizationCode->user_id);

            return true;
        }

        if ($authorizationCode->oauth_security_version === null
            || (int) $authorizationCode->oauth_security_version !== (int) $user->oauth_security_version) {
            $authorizationCode->forceFill(['revoked' => true])->save();

            return true;
        }

        // The code's provider stamp, checked against the account's binding and
        // handed to the token it mints (a no-op until enforcement is enabled).
        $providerIdentity = app(ProviderIdentityTokens::class);
        if ($providerIdentity->revoked($authorizationCode, remote: false)) {
            return true;
        }
        $providerIdentity->carry(request(), $authorizationCode);

        $this->accountGuard->recordValidatedGrant(
            $authorizationCode->user_id,
            (int) $authorizationCode->oauth_security_version,
            null,
            $storedResource,
        );

        return false;
    }
}
