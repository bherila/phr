<?php

namespace Tests\Feature\DelegatedAccess;

use App\Models\User;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRefusal;
use BWH\Auth\Testing\AssertsDelegatedAccessAdapter;
use Tests\Concerns\SeedsDelegatedAccessAccounts;
use Tests\TestCase;

/**
 * Searching `subjects`: a case-insensitive substring of the label or the address, over exactly the
 * accounts the unfiltered listing shows, with cursors bound to their search.
 */
class DelegatedAccessSearchTest extends TestCase
{
    use AssertsDelegatedAccessAdapter;
    use SeedsDelegatedAccessAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDelegatedAccess();

        $this->delegatedAccount('subject-bootstrap', 'admin');
        $this->delegatedAccount('subject-manager', 'admin');
    }

    protected function delegatedAccessTruth(string $subject): array
    {
        $user = User::query()->where('oauth_provider', self::DELEGATED_PROVIDER)->where('oauth_subject', $subject)->first();

        return ['application_admin' => (bool) $user?->hasRole('admin'), 'workspaces' => []];
    }

    protected function delegatedAccessManager(): string
    {
        return 'subject-manager';
    }

    public function test_a_search_matches_the_name_or_address_whatever_the_case(): void
    {
        $this->delegatedAccount('subject-a', 'user', attributes: ['name' => 'Zebrafinch Person', 'email' => 'a@example.test']);
        $this->delegatedAccount('subject-b', '', attributes: ['name' => 'Someone', 'email' => 'ZEBRAFINCH@example.test']);
        $this->delegatedAccount('subject-c', 'user', attributes: ['name' => 'Unrelated', 'email' => 'c@example.test']);

        $this->assertSame(['subject-a', 'subject-b'], $this->search('zEBRAfinch'));
        $this->assertSame(['subject-a'], $this->search('finch pers'));
        $this->assertSame(['subject-b'], $this->search('zebrafinch@'));
        $this->assertSame([], $this->search('nobody-matches'));
    }

    public function test_an_account_without_a_name_is_found_by_the_subject_its_label_shows(): void
    {
        $this->delegatedAccount('subject-unnamed-zebrafinch', 'user', attributes: ['name' => ' ', 'email' => 'x@example.test']);
        $this->delegatedAccount('subject-named-zebrafinch', 'user', attributes: ['name' => 'Named', 'email' => 'y@example.test']);

        $page = $this->delegatedAccessCall('subject-manager', ['operation' => 'subjects', 'query' => 'Zebrafinch']);
        $this->assertSame([['subject' => 'subject-unnamed-zebrafinch', 'label' => 'subject-unnamed-zebrafinch']],
            array_map(static fn (array $entry): array => ['subject' => $entry['subject'], 'label' => $entry['label']], $page['subjects']));
    }

    public function test_a_search_never_reaches_an_account_that_is_not_bound_whatever_its_address(): void
    {
        $this->delegatedAccount('subject-unbound', 'admin', provider: null, attributes: ['name' => 'Zebrafinch Legacy', 'email' => 'zebrafinch.legacy@example.test']);
        $this->delegatedAccount('subject-zebrafinch', 'user', provider: 'another-provider', attributes: ['email' => 'zebrafinch.elsewhere@example.test']);

        foreach ([1, 50] as $limit) {
            $page = $this->delegatedAccessCall('subject-manager', ['operation' => 'subjects', 'query' => 'zebrafinch', 'limit' => $limit]);
            $this->assertSame([], $page['subjects']);
            $this->assertNull($page['next_cursor']);
        }
    }

    public function test_wildcards_in_a_query_are_literal(): void
    {
        $this->delegatedAccount('subject-percent', 'user', attributes: ['name' => '100% Zebrafinch', 'email' => 'p@example.test']);
        $this->delegatedAccount('subject-plain', 'user', attributes: ['name' => '100 Zebrafinch', 'email' => 'q@example.test']);
        $this->delegatedAccount('subject-underscore', 'user', attributes: ['name' => 'zebra_finch', 'email' => 'r@example.test']);

        $this->assertSame(['subject-percent'], $this->search('0% z'));
        $this->assertSame(['subject-underscore'], $this->search('a_f'));
        $this->assertSame([], $this->search('!%'));
    }

    public function test_a_cursor_belongs_to_its_search(): void
    {
        foreach (['a', 'b', 'c'] as $suffix) {
            $this->delegatedAccount("subject-{$suffix}", 'user', attributes: ['name' => "Zebrafinch {$suffix}", 'email' => "{$suffix}@example.test"]);
        }

        $first = $this->delegatedAccessCall('subject-manager', ['operation' => 'subjects', 'query' => 'zebrafinch', 'limit' => 2]);
        $this->assertSame(['subject-a', 'subject-b'], array_column($first['subjects'], 'subject'));
        $second = $this->delegatedAccessCall('subject-manager', ['operation' => 'subjects', 'query' => 'zebrafinch', 'limit' => 2, 'cursor' => $first['next_cursor']]);
        $this->assertSame(['subject-c'], array_column($second['subjects'], 'subject'));
        $this->assertNull($second['next_cursor']);

        $this->assertRefused(DelegatedRefusal::INVALID_CURSOR, 'subject-manager', ['operation' => 'subjects', 'query' => 'finch', 'limit' => 2, 'cursor' => $first['next_cursor']]);
        $this->assertRefused(DelegatedRefusal::INVALID_CURSOR, 'subject-manager', ['operation' => 'subjects', 'limit' => 2, 'cursor' => $first['next_cursor']]);
    }

    public function test_an_actor_who_may_not_manage_access_cannot_search(): void
    {
        $this->delegatedAccount('subject-ordinary', 'user', attributes: ['name' => 'Zebrafinch']);

        $this->assertRefused(DelegatedRefusal::NOT_AUTHORIZED, 'subject-ordinary', ['operation' => 'subjects', 'query' => 'zebrafinch']);
        $this->assertRefused(DelegatedRefusal::NOT_AUTHORIZED, 'subject-ordinary', ['operation' => 'workspaces', 'query' => 'zebrafinch']);
    }

    /**
     * @return list<string>
     */
    private function search(string $query): array
    {
        return array_column($this->delegatedAccessCall('subject-manager', ['operation' => 'subjects', 'query' => $query])['subjects'], 'subject');
    }
}
