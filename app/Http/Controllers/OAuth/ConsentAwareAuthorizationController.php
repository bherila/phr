<?php

namespace App\Http\Controllers\OAuth;

use App\Support\AgentApi\OAuthClientConsents;
use App\Support\AgentApi\OAuthExchangeAccountGuard;
use App\Support\AgentApi\OAuthPatientConsents;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Scope;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport's authorization endpoint, held to PHR's durable consent.
 *
 * Passport approves without a prompt when the client skips authorization or
 * when the person already holds unexpired tokens for the requested scopes.
 * The second path must not outlive a revoked consent or cross to another
 * resource, so it also requires an unrevoked consent covering the request.
 * A silent approval stamps the code with that consent's version.
 */
class ConsentAwareAuthorizationController extends AuthorizationController
{
    public function authorize(ServerRequestInterface $psrRequest, Request $request, ResponseInterface $psrResponse, AuthorizationViewResponse $viewResponse): Response|AuthorizationViewResponse
    {
        $patients = app(OAuthPatientConsents::class);
        $patients->validateSelectionRequest($request);
        $request->session()->forget(OAuthPatientConsents::SELECTION_SESSION_KEY);
        $response = parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
        if ($patients->selectionRequested($request) && $response instanceof AuthorizationViewResponse) {
            $request->session()->put(OAuthPatientConsents::SELECTION_SESSION_KEY, $request->session()->get('authToken'));
        }

        return $response;
    }

    /** @param  Scope[]  $scopes */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        if (app(OAuthPatientConsents::class)->selectionRequested(request())) {
            return false;
        }
        $covered = app(OAuthClientConsents::class)->covering(
            $user->getAuthIdentifier(),
            (string) $client->getKey(),
            OAuthResourceIndicator::validatedFor(request()),
            array_values(array_map(static fn ($scope): string => $scope->id, $scopes)),
        ) !== null;

        return $covered && parent::hasGrantedScopes($user, $client, $scopes);
    }

    protected function approveRequest(AuthorizationRequestInterface $authRequest, ResponseInterface $psrResponse): Response
    {
        $user = $authRequest->getUser();
        if ($user !== null) {
            $consent = app(OAuthClientConsents::class)->covering(
                $user->getIdentifier(),
                $authRequest->getClient()->getIdentifier(),
                OAuthResourceIndicator::validatedFor(request()),
                array_values(array_map(
                    static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
                    $authRequest->getScopes(),
                )),
            );
            if ($consent !== null) {
                app(OAuthExchangeAccountGuard::class)->recordApprovedConsent($user->getIdentifier(), $consent->version);
            }
        }

        return parent::approveRequest($authRequest, $psrResponse);
    }
}
