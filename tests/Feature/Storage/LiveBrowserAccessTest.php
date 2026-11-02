<?php

namespace Tests\Feature\Storage;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\LiveObjectStore;
use Tests\TestCase;

/**
 * What a browser needs from the store, with the CORS rule docs/storage.md tells an operator
 * to set. A page can't send a PUT to another origin unless a preflight allows it, or read a
 * part's ETag unless the rule exposes it, and multipart can't complete without that ETag.
 * Curl-style clients skip all of this, so only a test that asks for it can see it broken.
 */
class LiveBrowserAccessTest extends TestCase
{
    private const string ORIGIN = 'https://phr.example.test';

    private string $key = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (! LiveObjectStore::configured()) {
            $this->markTestSkipped('Set PHR_TEST_S3_ENDPOINT to run against a local S3-compatible store.');
        }
        $this->key = 'run-'.Str::lower((string) Str::ulid()).'/browser/object.bin';
        LiveObjectStore::client()->putBucketCors([
            'Bucket' => $this->bucket(),
            'CORSConfiguration' => ['CORSRules' => [$this->documentedRule()]],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->key !== '') {
            LiveObjectStore::purge(dirname($this->key).'/');
            LiveObjectStore::abortUploads(dirname($this->key).'/');
            LiveObjectStore::client()->deleteBucketCors(['Bucket' => $this->bucket()]);
        }
        parent::tearDown();
    }

    private function bucket(): string
    {
        return (string) getenv('PHR_TEST_S3_BUCKET');
    }

    /** @return array<string, mixed> the rule from the docs, for this test's origin */
    private function documentedRule(): array
    {
        $docs = (string) file_get_contents(base_path('docs/storage.md'));
        $this->assertSame(1, preg_match('/```json\n(\{.*?"CORSRules".*?\})\n```/s', $docs, $block), 'docs/storage.md shows the CORS rule as JSON');
        $rule = json_decode($block[1], true, 16, JSON_THROW_ON_ERROR)['CORSRules'][0];
        $rule['AllowedOrigins'] = [self::ORIGIN];

        return $rule;
    }

    /** @return array{url: string, headers: array<string, string>} */
    private function signedPut(int $bytes): array
    {
        $client = LiveObjectStore::client();
        $request = $client->createPresignedRequest($client->getCommand('PutObject', ['Bucket' => $this->bucket(), 'Key' => $this->key]), '+5 minutes');

        return ['url' => (string) $request->getUri(), 'headers' => []];
    }

    public function test_a_preflight_for_a_put_from_the_app_origin_is_allowed(): void
    {
        $response = Http::withHeaders([
            'Origin' => self::ORIGIN,
            'Access-Control-Request-Method' => 'PUT',
            'Access-Control-Request-Headers' => 'content-type',
        ])->send('OPTIONS', (string) $this->signedPut(4)['url']);

        $this->assertTrue($response->successful(), (string) $response->status());
        $this->assertSame(self::ORIGIN, $response->header('Access-Control-Allow-Origin'));
        $this->assertStringContainsStringIgnoringCase('PUT', $response->header('Access-Control-Allow-Methods'));
        $this->assertStringContainsStringIgnoringCase('content-type', $response->header('Access-Control-Allow-Headers'));
    }

    public function test_a_preflight_from_another_origin_is_not_allowed(): void
    {
        $response = Http::withHeaders([
            'Origin' => 'https://elsewhere.example.test',
            'Access-Control-Request-Method' => 'PUT',
        ])->send('OPTIONS', (string) $this->signedPut(4)['url']);

        // Browsers need an OK status on a preflight. Garage answers 403, with a wildcard origin header.
        $this->assertFalse($response->successful(), (string) $response->status());
    }

    public function test_a_put_answers_with_an_etag_the_page_is_allowed_to_read(): void
    {
        $client = LiveObjectStore::client();
        $uploadId = (string) $client->createMultipartUpload(['Bucket' => $this->bucket(), 'Key' => $this->key])['UploadId'];
        $request = $client->createPresignedRequest($client->getCommand('UploadPart', [
            'Bucket' => $this->bucket(), 'Key' => $this->key, 'UploadId' => $uploadId, 'PartNumber' => 1,
        ]), '+5 minutes');

        $response = Http::withHeaders(['Origin' => self::ORIGIN])->withBody('synthetic', '')->put((string) $request->getUri());

        $this->assertTrue($response->successful(), (string) $response->status());
        $this->assertSame(self::ORIGIN, $response->header('Access-Control-Allow-Origin'));
        $this->assertStringContainsStringIgnoringCase('ETag', $response->header('Access-Control-Expose-Headers'));
        $this->assertNotSame('', $response->header('ETag'));
    }
}
