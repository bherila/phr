<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Unauthenticated requests to /api/phr/... must be answered with 401 JSON — never a
 * redirect toward a login page, and never a 500.
 *
 * The clients of this API (the React frontend's fetch wrapper, the Sinus Sentinel
 * desktop app) are written to treat 401 as "authentication required" and degrade
 * gracefully. Anything else — a 302 to /login, or a 500 from the framework trying to
 * resolve a `login` route that may not exist — falls through to a generic error path.
 * This contract is enforced by the `shouldRenderJsonWhen($request->is('api/*'))`
 * registration in bootstrap/app.php; these tests exist so that guard cannot be lost
 * without a red suite.
 */
class ApiUnauthenticatedResponseTest extends TestCase
{
    /**
     * The hard case: a client that does not send `Accept: application/json`. Without
     * the bootstrap guard, Laravel would try to redirect this request to the `login`
     * route — a 302 at best, a 500 (`Route [login] not defined`) if that route is
     * ever renamed or removed.
     */
    public function test_unauthenticated_api_request_without_json_accept_header_gets_401_json(): void
    {
        $response = $this->get('/api/phr/patients', ['Accept' => 'text/html']);

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_unauthenticated_api_request_with_json_accept_header_gets_401_json(): void
    {
        $this->getJson('/api/phr/patients')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_unauthenticated_api_write_request_gets_401_json(): void
    {
        $this->postJson('/api/phr/patients', ['name' => 'x'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * Device-ingest routes authenticate via AuthenticateWebOrMcpRequest (session or
     * bearer token) rather than the plain `auth` middleware; they must present the
     * same 401 JSON contract to an unauthenticated caller.
     */
    public function test_unauthenticated_device_ingest_request_gets_401_json(): void
    {
        $response = $this->get('/api/phr/patients/1/respiratory-events/summary', ['Accept' => 'text/html']);

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', 'application/json');
    }

    /**
     * Browser page routes keep the redirect behavior — only the API surface is JSON.
     */
    public function test_unauthenticated_web_page_still_redirects_to_login(): void
    {
        $this->get('/phr')->assertRedirect('/login');
    }
}
