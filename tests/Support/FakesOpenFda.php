<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Serves recorded openFDA responses (tests/Fixtures/openfda) so CI never calls
 * the live API. Package lookups answer from the fixture named for the package
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

    /** @var list<int> the database transaction depth at each request */
    protected array $openFdaTransactionLevels = [];

    /**
     * @param  list<string>  $listed  package NDCs to list in addition to the recorded ones
     */
    protected function fakeOpenFda(array $listed = [], bool $down = false): void
    {
        $this->openFdaRequests = [];
        $this->openFdaTransactionLevels = [];
        // A fresh factory, so a second call replaces the first fake instead of queueing behind it.
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($listed, $down) {
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
