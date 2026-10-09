<?php

namespace Tests\Feature\DelegatedAccess;

use App\Services\Accounts\PhrApplicationAccessAdapter;
use BWH\Auth\OAuth\DelegatedAccess\ApplicationAccessAdapter;
use BWH\Auth\OAuth\DelegatedAccess\NonceStore;
use DateTimeImmutable;
use Illuminate\Testing\TestResponse;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Tests\Concerns\SeedsDelegatedAccessAccounts;
use Tests\TestCase;

/**
 * POST /application-access reaches PHR's adapter through the package's endpoint, with a real
 * signed actor assertion, and stays closed by default: 404 until enabled, and writes refused
 * until they are switched on separately.
 */
class DelegatedAccessEndpointTest extends TestCase
{
    use SeedsDelegatedAccessAccounts;

    private const ENDPOINT = 'https://phr.example.test/application-access';

    private string $privateKey = '';

    private string $keyPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->delegatedAccount('subject-bootstrap', 'admin');
        $this->delegatedAccount('subject-manager', 'admin');
        $this->delegatedAccount('subject-target', 'user');
    }

    protected function tearDown(): void
    {
        if ($this->keyPath !== '') {
            @unlink($this->keyPath);
        }

        parent::tearDown();
    }

    public function test_the_adapter_is_bound_and_the_endpoint_is_off_by_default(): void
    {
        $this->assertInstanceOf(PhrApplicationAccessAdapter::class, $this->app->make(ApplicationAccessAdapter::class));
        $this->assertFalse((bool) config('bherila-auth.delegated_access.enabled'));
        $this->assertFalse((bool) config('bherila-auth.delegated_access.writes_enabled'));

        $this->postJson('/application-access', ['operation' => 'capabilities'])
            ->assertNotFound()
            ->assertExactJson(['error' => 'not_found']);
    }

    public function test_an_enabled_endpoint_answers_through_the_adapter_and_refuses_writes_until_they_are_enabled(): void
    {
        $this->enable(writes: false);

        $this->send('subject-manager', ['operation' => 'capabilities'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('controls', ['application_admin' => true, 'workspace_roles' => [], 'provisioning' => true]);

        $read = $this->send('subject-manager', ['operation' => 'read', 'subject' => 'subject-target'])->assertOk()->json();
        $this->assertTrue($read['allowed_edits']['application_admin']);

        $this->send('subject-manager', ['operation' => 'update', 'subject' => 'subject-target', 'expected_revision' => $read['revision'],
            'access' => ['application_admin' => true, 'workspaces' => []]])
            ->assertForbidden()
            ->assertExactJson(['error' => 'not_authorized']);
        $this->assertSame('user', $this->rawRoles(3));

        // An account that may not manage access is refused through the endpoint too.
        $this->send('subject-target', ['operation' => 'capabilities'])->assertForbidden()->assertExactJson(['error' => 'not_authorized']);
    }

    public function test_writes_reach_the_adapter_once_enabled(): void
    {
        $this->enable(writes: true);

        $read = $this->send('subject-manager', ['operation' => 'read', 'subject' => 'subject-target'])->assertOk()->json();
        $this->send('subject-manager', ['operation' => 'update', 'subject' => 'subject-target', 'expected_revision' => $read['revision'],
            'access' => ['application_admin' => true, 'workspaces' => []]])
            ->assertOk()
            ->assertJsonPath('access.application_admin', true);
        $this->assertSame('user,admin', $this->rawRoles(3));
    }

    private function enable(bool $writes): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $this->privateKey);
        $this->keyPath = (string) tempnam(sys_get_temp_dir(), 'phr-delegated-');
        file_put_contents($this->keyPath, openssl_pkey_get_details($key)['key']);

        $this->configureDelegatedAccess([
            'enabled' => true,
            'writes_enabled' => $writes,
            'endpoint' => self::ENDPOINT,
            'public_keys' => 'integration-v1|'.$this->keyPath,
        ]);

        // The database nonce store refuses in-memory SQLite, rightly.
        $this->app->instance(NonceStore::class, new class implements NonceStore
        {
            /** @var array<string, true> */
            private array $seen = [];

            public function consume(string $key, int $seconds): bool
            {
                if (isset($this->seen[$key])) {
                    return false;
                }

                return $this->seen[$key] = true;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function send(string $actor, array $input): TestResponse
    {
        $body = (string) json_encode(['contract_version' => 2, 'application' => self::DELEGATED_APPLICATION, ...$input]);
        $now = new DateTimeImmutable('@'.time());
        $assertion = Builder::new(new JoseEncoder, ChainedFormatter::withUnixTimestampDates())
            ->withHeader('typ', 'application-access+jwt')
            ->withHeader('kid', 'integration-v1')
            ->issuedBy(self::DELEGATED_ISSUER)->relatedTo($actor)->permittedFor(self::ENDPOINT)
            ->issuedAt($now)->expiresAt($now->modify('+60 seconds'))
            ->identifiedBy(bin2hex(random_bytes(32)))
            ->withClaim('application', self::DELEGATED_APPLICATION)
            ->withClaim('method', 'POST')
            ->withClaim('body_sha256', hash('sha256', $body))
            ->getToken(new Sha256, InMemory::plainText($this->privateKey))
            ->toString();

        return $this->call('POST', '/application-access', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$assertion,
        ], $body);
    }
}
