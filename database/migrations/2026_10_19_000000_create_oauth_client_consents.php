<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durable OAuth consent: one row per person, client and protected resource,
 * holding the scopes the person approved. `version` is the fence every
 * authorization code, rotation family and access token is stamped with; the
 * per-client revoker bumps it before sweeping credentials, so a grant validated
 * against an earlier version can never become usable.
 *
 * Credentials issued before consent was recorded were each approved on the
 * consent screen (no client skipped it), so live authorization-code families
 * are adopted as version 1 of a consent for their own scopes and resource
 * rather than being cut off. Personal access tokens carry no consent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oauth_client_consents')) {
            Schema::create('oauth_client_consents', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id');
                $table->uuid('client_id');
                $table->string('resource', 191);
                $table->text('scopes');
                $table->unsignedBigInteger('version')->default(1);
                $table->dateTime('granted_at');
                $table->dateTime('revoked_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'client_id', 'resource']);
                $table->index('client_id');
            });
        }

        if (Schema::hasTable('oauth_auth_codes') && ! Schema::hasColumn('oauth_auth_codes', 'oauth_consent_version')) {
            Schema::table('oauth_auth_codes', static function (Blueprint $table): void {
                $table->unsignedBigInteger('oauth_consent_version')->nullable();
            });
        }
        if (Schema::hasTable('oauth_access_tokens') && ! Schema::hasColumn('oauth_access_tokens', 'oauth_consent_version')) {
            Schema::table('oauth_access_tokens', static function (Blueprint $table): void {
                $table->unsignedBigInteger('oauth_consent_version')->nullable();
            });
        }
        if (Schema::hasTable('oauth_token_families') && ! Schema::hasColumn('oauth_token_families', 'oauth_consent_version')) {
            Schema::table('oauth_token_families', static function (Blueprint $table): void {
                $table->unsignedBigInteger('oauth_consent_version')->nullable();
            });
        }

        $this->adoptLiveAuthorizationCodeFamilies();
    }

    /** A skipped application-owned table or column cannot safely be removed on rollback. */
    public function down(): void {}

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }

    private function adoptLiveAuthorizationCodeFamilies(): void
    {
        $connection = DB::connection($this->getConnection());
        $schema = $connection->getSchemaBuilder();
        foreach (['oauth_clients', 'oauth_access_tokens', 'oauth_token_families'] as $required) {
            if (! $schema->hasTable($required)) {
                return;
            }
        }

        $authorizationCodeClients = $connection->table('oauth_clients')
            ->get(['id', 'grant_types'])
            ->filter(static function (object $client): bool {
                $grants = is_string($client->grant_types) ? json_decode($client->grant_types, true) : null;

                return is_array($grants) && in_array('authorization_code', $grants, true);
            })
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        if ($authorizationCodeClients === []) {
            return;
        }

        $now = now();
        $connection->table('oauth_token_families')
            ->where('revoked', false)
            ->where('expires_at', '>', $now)
            ->whereNotNull('user_id')
            ->whereNull('oauth_consent_version')
            ->whereIn('client_id', $authorizationCodeClients)
            ->chunkById(200, function ($families) use ($connection, $now): void {
                foreach ($families as $family) {
                    $tokens = $connection->table('oauth_access_tokens')
                        ->where('user_id', $family->user_id)
                        ->where('client_id', $family->client_id)
                        ->where(static function ($query) use ($family): void {
                            $query->where('oauth_family_id', $family->id)->orWhere('id', $family->id);
                        })
                        ->orderBy('created_at')
                        ->get(['id', 'scopes', 'resource_uri']);
                    $latest = $tokens->last();
                    if ($latest === null || ! is_string($latest->resource_uri) || $latest->resource_uri === '') {
                        continue;
                    }
                    $scopes = [];
                    foreach ($tokens as $token) {
                        $decoded = is_string($token->scopes) ? json_decode($token->scopes, true) : null;
                        foreach (is_array($decoded) ? $decoded : [] as $scope) {
                            if (is_string($scope)) {
                                $scopes[$scope] = true;
                            }
                        }
                    }

                    $key = [
                        'user_id' => $family->user_id,
                        'client_id' => $family->client_id,
                        'resource' => $latest->resource_uri,
                    ];
                    $existing = $connection->table('oauth_client_consents')->where($key)->first();
                    if ($existing === null) {
                        $scopeList = array_keys($scopes);
                        sort($scopeList);
                        $connection->table('oauth_client_consents')->insert($key + [
                            'scopes' => json_encode($scopeList, JSON_THROW_ON_ERROR),
                            'version' => 1,
                            'granted_at' => $family->created_at ?? $now,
                            'revoked_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $version = 1;
                    } else {
                        $stored = json_decode((string) $existing->scopes, true);
                        $merged = array_keys($scopes + array_fill_keys(is_array($stored) ? $stored : [], true));
                        sort($merged);
                        $connection->table('oauth_client_consents')->where('id', $existing->id)->update([
                            'scopes' => json_encode($merged, JSON_THROW_ON_ERROR),
                            'updated_at' => $now,
                        ]);
                        $version = (int) $existing->version;
                    }

                    $connection->table('oauth_token_families')->where('id', $family->id)
                        ->update(['oauth_consent_version' => $version]);
                    $connection->table('oauth_access_tokens')->whereIn('id', $tokens->pluck('id')->all())
                        ->update(['oauth_consent_version' => $version]);
                }
            });
    }
};
