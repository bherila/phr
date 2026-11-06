<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Writes actual HTTP bytes, never an associative decode/re-encode of a response. */
final class HttpContractFixtures
{
    /** @var array<string, array<string, mixed>> */
    private array $cases = [];

    /** @var list<array<string, mixed>> */
    private array $fixtures = [];

    private readonly string $directory;

    private readonly string $candidate;

    private readonly string $generation;

    public function __construct()
    {
        umask(0077);
        $this->candidate = (string) getenv('PHR_HTTP_CONTRACT_CANDIDATE');
        $this->generation = (string) getenv('PHR_HTTP_CONTRACT_GENERATION');
        $this->directory = (string) getenv('PHR_HTTP_CONTRACT_DIR');
        exec('git rev-parse HEAD', $gitHead, $status);
        self::require($status === 0 && preg_match('/^[a-f0-9]{40}$/D', $this->candidate) === 1
            && implode('', $gitHead) === $this->candidate, 'generation', 'candidate');
        self::require(preg_match('/^[a-f0-9]{32}$/D', $this->generation) === 1
            && realpath($this->directory) === base_path('build/http-contracts/run-'.$this->generation.'/fixtures')
            && ! is_link($this->directory) && scandir($this->directory) === ['.', '..'], 'generation', 'directory');
        $catalog = json_decode(file_get_contents(base_path('tests/contracts/catalog.json')), true, 32, JSON_THROW_ON_ERROR);
        foreach ($catalog['cases'] as $case) {
            self::require(is_string($case['id']) && preg_match('/^[a-z][a-z0-9-]{1,80}$/D', $case['id']) === 1
                && ! isset($this->cases[$case['id']]), 'catalog', 'case');
            $this->cases[$case['id']] = $case;
        }
        self::require(count($this->cases) > 0, 'catalog', 'empty');
    }

    /** @param TestResponse<Response> $response */
    public function capture(string $id, TestResponse $response): void
    {
        self::require(isset($this->cases[$id]), 'catalog', 'unknown_case');
        $case = $this->cases[$id];
        self::require(! file_exists($this->directory.'/'.$id.'.json'), $id, 'duplicate');
        self::require($response->getStatusCode() === $case['status'], $id, 'http_status');
        $type = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'))[0]));
        self::require($type === 'application/json', $id, 'content_type');
        $body = $response->getContent();
        self::require(is_string($body) && strlen($body) > 0 && strlen($body) <= 1024 * 1024, $id, 'body_size');
        file_put_contents($this->directory.'/'.$id.'.json', $body, LOCK_EX);
        $this->fixtures[] = [
            'id' => $id, 'schema' => $case['schema'], 'role' => $case['role'], 'state' => $case['state'],
            'candidate' => $this->candidate, 'generation' => $this->generation,
            'status' => $response->getStatusCode(), 'content_type' => $type,
            'file' => $id.'.json', 'bytes' => strlen($body), 'sha256' => hash('sha256', $body),
        ];
    }

    /** Publish the manifest last: a failed producer never leaves a usable partial generation. */
    public function finish(): int
    {
        self::require(count($this->fixtures) === count($this->cases), 'generation', 'incomplete');
        $sources = json_decode(file_get_contents(base_path('tests/contracts/sources.json')), true, 32, JSON_THROW_ON_ERROR);
        $fingerprints = [];
        foreach ($sources as $source) {
            $fingerprints[$source] = hash_file('sha256', base_path($source));
        }
        ksort($fingerprints);
        $manifest = [
            'version' => 1, 'candidate' => $this->candidate, 'generation' => $this->generation,
            'catalog_sha256' => hash_file('sha256', base_path('tests/contracts/catalog.json')),
            'generator_fingerprints' => $fingerprints, 'fixtures' => $this->fixtures,
        ];
        file_put_contents($this->directory.'/manifest.tmp', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($this->directory.'/manifest.tmp', $this->directory.'/manifest.json');

        return count($this->fixtures);
    }

    public static function require(bool $condition, string $id, string $code): void
    {
        if (! $condition) {
            throw new RuntimeException('http-contract case='.$id.' code='.$code);
        }
    }
}
