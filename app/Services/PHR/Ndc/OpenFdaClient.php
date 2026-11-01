<?php

namespace App\Services\PHR\Ndc;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Read-only client for the openFDA drug NDC directory.
 *
 * Only drug descriptors (an NDC, a drug name, a strength) are ever sent; no
 * patient data reaches openFDA. Package lookups are cached by package NDC,
 * including the "not listed" answer for a shorter time, so a repeated lookup
 * costs nothing against the daily request allowance.
 */
class OpenFdaClient
{
    private const string CACHE_PREFIX = 'openfda:ndc-package:';

    /**
     * The product listing that contains exactly this hyphenated package NDC.
     *
     * @return array{product: array<string, mixed>, fetched_at: string}|null null when openFDA does not list the package
     *
     * @throws OpenFdaUnavailableException
     */
    public function findPackage(string $packageNdc): ?array
    {
        $key = self::CACHE_PREFIX.$packageNdc;
        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('found', $cached)) {
            return $cached['found'] === true && is_array($cached['entry'] ?? null) ? $cached['entry'] : null;
        }

        $response = $this->get([
            'search' => 'packaging.package_ndc:"'.$packageNdc.'"',
            'limit' => 5,
        ]);
        $entry = null;
        if ($response !== null) {
            foreach ($response as $product) {
                foreach ((array) ($product['packaging'] ?? []) as $package) {
                    if (is_array($package) && strcasecmp((string) ($package['package_ndc'] ?? ''), $packageNdc) === 0) {
                        $entry = ['product' => $product, 'fetched_at' => Carbon::now()->toIso8601String()];
                        break 2;
                    }
                }
            }
        }

        $ttl = $entry === null
            ? (int) config('services.openfda.negative_cache_ttl_seconds', 21600)
            : (int) config('services.openfda.cache_ttl_seconds', 86400);
        Cache::put($key, ['found' => $entry !== null, 'entry' => $entry], max(1, $ttl));

        return $entry;
    }

    /**
     * Products matching an openFDA search expression, newest listing first as
     * openFDA orders them. Not cached: searches are open-ended.
     *
     * @return list<array<string, mixed>>
     *
     * @throws OpenFdaUnavailableException
     */
    public function search(string $expression, int $limit = 25): array
    {
        return $this->get(['search' => $expression, 'limit' => max(1, min(100, $limit))]) ?? [];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>|null null when openFDA answers 404 "no matches"
     *
     * @throws OpenFdaUnavailableException
     */
    private function get(array $query): ?array
    {
        $key = config('services.openfda.key');
        if (is_string($key) && $key !== '') {
            $query['api_key'] = $key;
        }

        try {
            $response = $this->request()->get('/drug/ndc.json', $query);
        } catch (ConnectionException $exception) {
            throw new OpenFdaUnavailableException('openFDA could not be reached.', 0, $exception);
        }

        return $this->results($response);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function results(Response $response): ?array
    {
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new OpenFdaUnavailableException("openFDA answered HTTP {$response->status()}.");
        }
        $results = $response->json('results');
        if (! is_array($results)) {
            throw new OpenFdaUnavailableException('openFDA returned an unexpected response.');
        }

        return array_values(array_filter($results, is_array(...)));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('services.openfda.base_url', 'https://api.fda.gov'))
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(max(1, (int) config('services.openfda.timeout', 5)))
            // One retry for a dropped connection, a rate limit or a server error. A 404 is an
            // answer ("no such product"), not a failure, and is never retried.
            ->retry(1, 100, static fn (\Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)), throw: false);
    }
}
