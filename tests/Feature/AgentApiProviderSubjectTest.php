<?php

namespace Tests\Feature;

use App\Models\AgentApiAudit;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * identity.get returns the caller's subject at the trusted identity provider,
 * so a connecting application can check it reached the account of the person
 * who connected. It is a stable cross-application identifier, not a secret,
 * and appears only in that authorized self-description.
 */
final class AgentApiProviderSubjectTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private const string SUBJECT = 'synthetic-subject-4711';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_identity_get_returns_the_trusted_provider_subject(): void
    {
        Passport::actingAs($this->bound((string) config('bherila-auth.oauth_client.provider'), self::SUBJECT), [AgentApiScopes::IDENTITY_READ]);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('identity.provider_subject', self::SUBJECT);
    }

    public function test_an_unbound_or_foreign_binding_has_no_provider_subject(): void
    {
        Passport::actingAs($this->bound(null, null), [AgentApiScopes::IDENTITY_READ]);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('identity.provider_subject', null);

        Passport::actingAs($this->bound('synthetic-other-provider', self::SUBJECT), [AgentApiScopes::IDENTITY_READ]);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('identity.provider_subject', null);
    }

    public function test_the_subject_stays_out_of_refusals_public_responses_and_logs(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $user = $this->bound((string) config('bherila-auth.oauth_client.provider'), self::SUBJECT);

        Passport::actingAs($user, [AgentApiScopes::PATIENTS_READ]);
        $this->assertStringNotContainsString(self::SUBJECT, (string) $this->getJson('/api/v1/me')->assertForbidden()->getContent());
        $this->assertStringNotContainsString(self::SUBJECT, (string) $this->getJson('/api/v1/capabilities')->assertOk()->getContent());
        $this->assertStringNotContainsString(self::SUBJECT, (string) $this->getJson('/api/openapi.json')->assertOk()->getContent());

        Passport::actingAs($user, [AgentApiScopes::IDENTITY_READ]);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('identity.provider_subject', self::SUBJECT);

        $this->assertNotSame(0, AgentApiAudit::query()->count());
        $this->assertStringNotContainsString(self::SUBJECT, json_encode(AgentApiAudit::query()->get()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::SUBJECT, implode("\n", $logged));
    }

    private function bound(?string $provider, ?string $subject): User
    {
        $user = $this->createUser();
        $user->forceFill(['oauth_provider' => $provider, 'oauth_subject' => $subject])->save();

        return $user->fresh() ?? $user;
    }
}
