<?php

namespace App\Support\AgentApi;

use App\Models\OAuthClientConsent;
use App\Models\OAuthPatientConsent;
use App\Models\PhrNativeRecordIdentity;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Identity\PhrPublicIds;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;

/** Per-client patient choices, fenced by the existing resource consent's version. */
final class OAuthPatientConsents
{
    public const array CAPABILITY = [
        'version' => 1, 'authorization_parameter' => 'patient_select',
        'token_response_parameter' => 'patient', 'patient_identifiers' => 'public_id',
        'selection_roles' => ['owner', 'manager'],
    ];

    public const string SELECTION_SESSION_KEY = 'oauth.patient_selection_auth_token';

    public function __construct(
        private OAuthClientConsents $consents,
        private PhrPatientAccessService $access,
        private PhrPublicIds $publicIds,
    ) {}

    public function selectionRequested(Request $request): bool
    {
        return $request->query->has('patient_select');
    }

    public function validateSelectionRequest(Request $request): void
    {
        if (! $this->selectionRequested($request)) {
            return;
        }
        $scope = $request->query('scope');
        $clientId = $request->query('client_id');
        $client = is_string($clientId) ? Passport::client()->newQuery()->find($clientId) : null;
        $registeredAt = config('bherila-auth.oauth_server.dynamic_clients.registered_at_column', 'dynamically_registered_at');
        abort_unless($request->query('patient_select') === '1'
            && is_string($scope) && in_array(AgentApiScopes::BILLING_READ, explode(' ', $scope), true)
            && $client !== null && $client->hasGrantType('authorization_code')
            && is_string($registeredAt) && $client->getAttribute($registeredAt) === null
            && $client->hasScope(AgentApiScopes::BILLING_READ), 422, 'Patient selection requires a registered billing client.');
    }

    /** @return list<array{public_id: string, label: string}> */
    public function options(int $userId): array
    {
        return $this->access->writablePatientsQuery($userId)->orderBy('id')->get()
            ->map(fn (PhrPatient $patient): array => [
                'public_id' => $this->publicIds->forPatient((int) $patient->id),
                'label' => $patient->display_name ?? 'Unnamed patient',
            ])->all();
    }

    public function selectedPatient(int $userId, mixed $publicId): PhrPatient
    {
        abort_unless(is_string($publicId) && Str::isUuid($publicId), 422, 'Choose one patient you can manage.');
        $id = PhrNativeRecordIdentity::query()->where('record_table', PhrPublicIds::PATIENTS)
            ->whereColumn('record_id', 'patient_id')->where('native_id', $publicId)->value('record_id');
        $patient = $this->access->writablePatientsQuery($userId)->whereKey($id)->first();
        abort_unless($patient instanceof PhrPatient, 422, 'Choose one patient you can manage.');

        return $patient;
    }

    public function grant(OAuthClientConsent $parent, PhrPatient $patient): OAuthPatientConsent
    {
        $publicId = $this->publicIds->forPatient((int) $patient->id);
        $connection = config('passport.connection');

        return DB::connection(is_string($connection) ? $connection : null)->transaction(function () use ($parent, $publicId): OAuthPatientConsent {
            OAuthPatientConsent::query()->createOrFirst(['consent_id' => $parent->id, 'patient_public_id' => $publicId], [
                'client_consent_version' => $parent->version, 'version' => 0,
                'granted_at' => now(), 'revoked_at' => now(),
            ]);
            $grant = OAuthPatientConsent::query()->where('consent_id', $parent->id)->where('patient_public_id', $publicId)->lockForUpdate()->firstOrFail();
            if ($grant->revoked_at !== null || $grant->client_consent_version !== $parent->version) {
                $grant->forceFill([
                    'client_consent_version' => $parent->version, 'version' => $grant->version + 1,
                    'granted_at' => now(), 'revoked_at' => null,
                ])->save();
            }

            return $grant;
        });
    }

    public function current(int|string $userId, string $clientId, ?string $resource, string $publicId, ?int $version = null): bool
    {
        $parent = $this->consents->covering($userId, $clientId, $resource, [AgentApiScopes::BILLING_READ]);
        if ($parent === null) {
            return false;
        }
        $grant = $this->grants($parent)->where('patient_public_id', $publicId);
        if ($version !== null) {
            $grant->where('version', $version);
        }
        if (! $grant->exists()) {
            return false;
        }

        return $this->writableWithPublicIds((int) $userId, [$publicId])->exists();
    }

    /**
     * Apply only at billing-capable endpoints; clinical-only operations remain unchanged.
     *
     * @param  Builder<PhrPatient>  $query
     */
    public function restrict(Request $request, Builder $query): void
    {
        if (! $request->user('api')?->tokenCan(AgentApiScopes::BILLING_READ)) {
            return;
        }
        $identity = $this->identity($request);
        $parent = $identity === null ? null : $this->consents->covering($identity[0], $identity[1], $identity[2], [AgentApiScopes::BILLING_READ]);
        $publicIds = $parent === null ? [] : $this->grants($parent)->pluck('patient_public_id')->all();
        $query->whereIn('phr_patients.id', $this->writableWithPublicIds((int) ($identity[0] ?? 0), $publicIds)->select('phr_patients.id'));
    }

    public function assertPatient(Request $request, int $patientId): void
    {
        if (! $request->user('api')?->tokenCan(AgentApiScopes::BILLING_READ)) {
            return;
        }
        $query = PhrPatient::query()->whereKey($patientId);
        $this->restrict($request, $query);
        abort_unless($query->exists(), 404);
    }

    public function revoke(Request $request, string $publicId): void
    {
        $identity = $this->identity($request);
        $parent = $identity === null ? null : $this->consents->find(...$identity);
        if ($parent !== null) {
            // No client-wide version bump: the other selected patients remain available.
            $this->grants($parent)->where('patient_public_id', $publicId)
                ->update(['revoked_at' => now(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
    }

    /** @return array{int, string, string}|null */
    private function identity(Request $request): ?array
    {
        $user = $request->user('api');
        $token = $user?->token();
        $attributes = $token instanceof AccessToken ? $token->toArray() : [];
        $clientId = $attributes['oauth_client_id'] ?? null;

        return $user !== null && is_string($clientId) && $clientId !== ''
            ? [(int) $user->getAuthIdentifier(), $clientId, OAuthResourceIndicator::expectedFor($request) ?? OAuthResourceIndicator::resource('rest')]
            : null;
    }

    /** @return Builder<OAuthPatientConsent> */
    private function grants(OAuthClientConsent $parent): Builder
    {
        return OAuthPatientConsent::query()->where('consent_id', $parent->id)
            ->where('client_consent_version', $parent->version)->whereNull('revoked_at');
    }

    /**
     * @param  list<string>  $publicIds
     * @return Builder<PhrPatient>
     */
    private function writableWithPublicIds(int $userId, array $publicIds): Builder
    {
        return $this->access->writablePatientsQuery($userId)->whereIn('phr_patients.id', PhrNativeRecordIdentity::query()
            ->where('record_table', PhrPublicIds::PATIENTS)->whereColumn('record_id', 'patient_id')
            ->whereIn('native_id', $publicIds)->select('record_id'));
    }
}
