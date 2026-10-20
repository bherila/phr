<?php

namespace App\Http\Controllers\OAuth;

use App\Support\AgentApi\OAuthClientConsents;
use App\Support\AgentApi\OAuthDynamicClientDao;
use App\Support\AgentApi\OAuthExchangeAccountGuard;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Http\Request;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The consent screen's approval: records the person's durable consent for the
 * client, scopes and resource they just approved, and stamps the issued
 * authorization code with that consent's version. Runs inside the approval
 * transaction SerializeOAuthTokenExchange opens.
 */
class ConsentRecordingApproveAuthorizationController extends ApproveAuthorizationController
{
    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->getAuthRequestFromSession($request);

        return $this->withErrorHandling(function () use ($request, $psrResponse, $authRequest): Response {
            // A screen opened before operator revocation must not recreate a
            // consent or dead code after cleanup. Hold this fence through both.
            if (app(OAuthDynamicClientDao::class)->lockForAuthorization($authRequest->getClient()->getIdentifier()) === null) {
                throw OAuthServerException::invalidGrant('The authorization grant is invalid.');
            }
            $user = $authRequest->getUser();
            if ($user !== null) {
                $consent = app(OAuthClientConsents::class)->grant(
                    $user->getIdentifier(),
                    $authRequest->getClient()->getIdentifier(),
                    OAuthResourceIndicator::validatedFor($request),
                    array_values(array_map(
                        static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
                        $authRequest->getScopes(),
                    )),
                );
                if ($consent !== null) {
                    app(OAuthExchangeAccountGuard::class)->recordApprovedConsent($user->getIdentifier(), $consent->version);
                }
            }

            $authRequest->setAuthorizationApproved(true);

            return $this->convertResponse($this->server->completeAuthorizationRequest($authRequest, $psrResponse));
        }, $authRequest->getGrantTypeId() === 'implicit');
    }
}
