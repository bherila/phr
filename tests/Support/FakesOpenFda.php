<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Serves recorded openFDA responses (tests/Fixtures/openfda) so CI never calls
 * the live API. RxNav, the fallback for NDCs openFDA does not list, is served from
 * tests/Fixtures/rxnav: the recorded NDCs answer from their files, and any other NDC
 * is "UNKNOWN" to RxNorm, as the live service answers (see fakeOpenFda's $rxnavDown). Package lookups answer from the fixture named for the package
 * NDC and otherwise 404, as openFDA does; any other search gets the recorded
 * atorvastatin search.
 *
 * The fixtures are real responses trimmed to the products used. Two kinds of
 * synthetic listing are copies of a recorded product under another package code:
 * - Any package a test passes in `$listed` is a copy of the 0002-0800-01 product,
 *   to model a ten-digit NDC without hyphens that two real packages could mean
 *   (0002080001 reads as 0002-0800-01 and 00020-800-01).
 * - `60505-134-00` is always served as a copy of the cyclosporine product: the
 *   owner's hard-coded rule turns the billing NDC 60505-0134-00 into it, though
 *   openFDA lists that product as 60505-0134-0.
 */
trait FakesOpenFda
{
    /** @var list<string> every request URL, in order */
    protected array $openFdaRequests = [];

    /** @var list<string> every RxNav request URL, in order */
    protected array $rxnavRequests = [];

    /** @var (\Closure(string): void)|null called with the URL of each RxNav request, before it is answered */
    protected ?\Closure $onRxnavRequest = null;

    /** @var list<int> the database transaction depth at each request, to openFDA or RxNav */
    protected array $openFdaTransactionLevels = [];

    /**
     * @param  list<string>  $listed  package NDCs to list in addition to the recorded ones
     * @param  bool  $down  openFDA answers 503
     * @param  bool  $rxnavDown  RxNav answers 503
     */
    protected function fakeOpenFda(array $listed = [], bool $down = false, bool $rxnavDown = false): void
    {
        $this->openFdaRequests = [];
        $this->rxnavRequests = [];
        $this->openFdaTransactionLevels = [];
        // A fresh factory, so a second call replaces the first fake instead of queueing behind it.
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($listed, $down, $rxnavDown) {
            if (str_contains($request->url(), 'rxnav')) {
                $this->rxnavRequests[] = $request->url();
                if ($this->onRxnavRequest !== null) {
                    ($this->onRxnavRequest)($request->url());
                }
                $this->openFdaTransactionLevels[] = DB::transactionLevel();

                return $rxnavDown ? Http::response(['error' => 'unavailable'], 503) : $this->rxnavResponse($request->url());
            }
            $this->openFdaRequests[] = $request->url();
            $this->openFdaTransactionLevels[] = DB::transactionLevel();
            if ($down) {
                return Http::response(['error' => 'unavailable'], 503);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $search = (string) ($query['search'] ?? '');
            if (preg_match('/\Apackaging\.package_ndc:"([0-9-]+)"\z/', $search, $match) === 1) {
                return $this->packageResponse($match[1], $listed);
            }

            return Http::response($this->fixture('search-atorvastatin.json'));
        });
    }

    private function rxnavResponse(string $url): mixed
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $dir = __DIR__.'/../Fixtures/rxnav/';
        if (str_ends_with($path, '/ndcstatus.json')) {
            $ndc = (string) ($query['ndc'] ?? '');
            $file = $dir.'ndcstatus-'.$ndc.'.json';
            if (is_file($file)) {
                return Http::response(json_decode((string) file_get_contents($file), true));
            }
            $unknown = json_decode((string) file_get_contents($dir.'ndcstatus-unknown.json'), true);
            $unknown['ndcStatus']['ndc11'] = $ndc;

            return Http::response($unknown);
        }
        if (str_ends_with($path, '/ndcproperties.json')) {
            $file = $dir.'ndcproperties-'.(string) ($query['id'] ?? '').'.json';

            return Http::response(json_decode((string) file_get_contents(is_file($file) ? $file : $dir.'ndcproperties-unknown.json'), true));
        }
        if (preg_match('#/rxcui/([0-9]+)/related\.json\z#', $path, $match) === 1 && is_file($dir.'related-'.$match[1].'.json')) {
            return Http::response(json_decode((string) file_get_contents($dir.'related-'.$match[1].'.json'), true));
        }

        return Http::response(['error' => 'not recorded'], 404);
    }

    /** @param list<string> $listed */
    private function packageResponse(string $packageNdc, array $listed): mixed
    {
        $file = __DIR__.'/../Fixtures/openfda/package-'.$packageNdc.'.json';
        if (is_file($file)) {
            return Http::response($this->fixture('package-'.$packageNdc.'.json'));
        }
        if ($packageNdc === '60505-134-00') {
            return Http::response(json_decode(str_replace('60505-0134-0', '60505-134-00', (string) file_get_contents(__DIR__.'/../Fixtures/openfda/package-60505-0134-0.json')), true));
        }
        if (in_array($packageNdc, $listed, true)) {
            return Http::response(json_decode(str_replace('0002-0800-01', $packageNdc, (string) file_get_contents(__DIR__.'/../Fixtures/openfda/package-0002-0800-01.json')), true));
        }

        return Http::response($this->fixture('not-found.json'), 404);
    }

    /** @return array<string, mixed> */
    protected function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/openfda/'.$name), true, 512, JSON_THROW_ON_ERROR);
    }
}
