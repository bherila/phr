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
 * Read-only client for NLM RxNav, used when openFDA has no listing for an NDC
 * (a discontinued product). RxNorm keeps the history of every NDC it has ever
 * mapped, so it still knows the drug.
 *
 * Only an NDC or an RxCUI is ever sent; no patient data reaches NLM. Answers
 * are cached by NDC and by RxCUI, including the "not in RxNorm" answer for a
 * shorter time. An outage is never cached.
 *
 * Three read-only calls make one NDC's answer:
 * - `ndcstatus` (is the NDC in RxNorm, its RxCUI and its history),
 * - `ndcproperties` (labeler, packaging, marketing status, SPL details),
 * - `rxcui/{rxcui}/related` with tty SCDC+DF (one clean "ingredient strength"
 *   per ingredient, and the dose form). RxTerms `allinfo` was not used: its
 *   strength is one string ("300-30 mg") and its route and form are display
 *   words ("Oral Pill", "Tab").
 */
class RxNavClient
{
    private const string NDC_PREFIX = 'rxnav:ndc:';

    private const string RXCUI_PREFIX = 'rxnav:rxcui:';

    /**
     * What RxNorm knows about one eleven-digit NDC (5-4-2, no hyphens), as the raw
     * responses, or null when RxNorm has no usable concept for it.
     *
     * @return array{ndcstatus: array<string, mixed>, ndcproperties: array<string, mixed>, related: array<string, mixed>, rxcui: string, fetched_at: string}|null
     *
     * @throws RxNavUnavailableException
     */
    public function findNdc(string $ndc11): ?array
    {
        $key = self::NDC_PREFIX.$ndc11;
        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('found', $cached)) {
            return $cached['found'] === true && is_array($cached['entry'] ?? null) ? $cached['entry'] : null;
        }

        $entry = $this->fetchNdc($ndc11);
        $ttl = $entry === null
            ? (int) config('services.rxnav.negative_cache_ttl_seconds', 21600)
            : (int) config('services.rxnav.cache_ttl_seconds', 86400);
        Cache::put($key, ['found' => $entry !== null, 'entry' => $entry], max(1, $ttl));

        return $entry;
    }

    /**
     * @return array{ndcstatus: array<string, mixed>, ndcproperties: array<string, mixed>, related: array<string, mixed>, rxcui: string, fetched_at: string}|null
     *
     * @throws RxNavUnavailableException
     */
    private function fetchNdc(string $ndc11): ?array
    {
        $status = $this->get('/ndcstatus.json', ['ndc' => $ndc11]);
        $rxcui = RxNavListing::usableRxcui($status ?? []);
        if ($status === null || $rxcui === null) {
            return null;
        }

        $properties = $this->get('/ndcproperties.json', ['id' => $ndc11, 'ndcstatus' => 'ALL']) ?? [];
        $related = $this->related($rxcui);
        if ($related === null) {
            return null;
        }

        return [
            'ndcstatus' => $status,
            'ndcproperties' => $properties,
            'related' => $related,
            'rxcui' => $rxcui,
            'fetched_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * The ingredient-with-strength and dose-form concepts of an RxCUI, or null when RxNorm has none.
     *
     * @return array<string, mixed>|null
     *
     * @throws RxNavUnavailableException
     */
    public function related(string $rxcui): ?array
    {
        $key = self::RXCUI_PREFIX.$rxcui;
        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('found', $cached)) {
            return $cached['found'] === true && is_array($cached['entry'] ?? null) ? $cached['entry'] : null;
        }

        $body = $this->get('/rxcui/'.rawurlencode($rxcui).'/related.json', ['tty' => 'SCDC+DF+IN']);
        $entry = $body !== null && RxNavListing::hasConcepts($body) ? $body : null;
        $ttl = $entry === null
            ? (int) config('services.rxnav.negative_cache_ttl_seconds', 21600)
            : (int) config('services.rxnav.cache_ttl_seconds', 86400);
        Cache::put($key, ['found' => $entry !== null, 'entry' => $entry], max(1, $ttl));

        return $entry;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>|null null when RxNav answers 404
     *
     * @throws RxNavUnavailableException
     */
    private function get(string $path, array $query): ?array
    {
        try {
            // The "+" in a tty list is RxNav's own separator, so the query is built by hand.
            $response = $this->request()->get($path.'?'.implode('&', array_map(
                static fn (string $name, string $value): string => $name.'='.($name === 'tty' ? $value : rawurlencode($value)),
                array_keys($query),
                array_values($query),
            )));
        } catch (ConnectionException $exception) {
            throw new RxNavUnavailableException('RxNav could not be reached.', 0, $exception);
        }

        return $this->body($response);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function body(Response $response): ?array
    {
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new RxNavUnavailableException("RxNav answered HTTP {$response->status()}.");
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new RxNavUnavailableException('RxNav returned an unexpected response.');
        }

        return $body;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('services.rxnav.base_url', 'https://rxnav.nlm.nih.gov/REST'))
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(max(1, (int) config('services.rxnav.timeout', 5)))
            // Two attempts in all (one retry) for a dropped connection, a rate limit or a server error.
            ->retry(2, 100, static fn (\Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)), throw: false);
    }
}
