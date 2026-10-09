<?php

namespace App\Services\Accounts;

use App\Models\User;
use BWH\Auth\Models\AuthAuditLog;
use BWH\Auth\OAuth\DelegatedAccess\ApplicationAccessAdapter;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessSettings;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedCursor;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRefusal;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRequestContext;
use BWH\Auth\OAuth\PendingAccount;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Lets the identity provider's "manage users" screen manage PHR accounts (delegated access
 * contract version 2).
 *
 * PHR is account-only: it has accounts and an administrator role, and no workspaces. Patient
 * sharing is between people inside PHR and is not exposed here. What can be managed is whether
 * a provider subject has an account (provisioning) and whether that account is an administrator.
 *
 * Roles live in `users.user_role` as a comma-separated list. `admin` maps to
 * `application_admin`; every other role is left exactly as it is. `admin` also lets an account
 * sign in (User::canLogin()), so removing it adds `user` when nothing else would: a demoted
 * administrator keeps an ordinary account rather than being disabled as a side effect.
 *
 * One rule decides whether an account's administrator role may change, reported as
 * `allowed_edits.application_admin` and enforced again on the locked rows of an update:
 *  - account #1 is always an administrator (User::hasRole) and is never editable;
 *  - nobody changes their own administrator role;
 *  - an account that cannot sign in is not promoted, since that would re-enable it;
 *  - the last administrator who can still sign in is not demoted.
 *
 * Only an existing, active PHR administrator bound to the sign-in provider may use any
 * operation. Everyone else is refused every operation, the read-only ones included.
 */
final class PhrApplicationAccessAdapter implements ApplicationAccessAdapter
{
    /** Audit events, in the authentication audit log, for changes made through delegated access. */
    public const EVENT_PROVISIONED = 'delegated_access_provisioned';

    public const EVENT_ADMIN_GRANTED = 'delegated_access_admin_granted';

    public const EVENT_ADMIN_REVOKED = 'delegated_access_admin_revoked';

    private const PAGE_LIMIT = 50;

    public function __construct(
        private readonly DelegatedAccessSettings $settings,
        private readonly DelegatedCursor $cursor,
        private readonly Container $container,
    ) {}

    public function handle(string $actorSubject, array $payload): array
    {
        $actor = $this->administrator($actorSubject) ?? throw DelegatedRefusal::of(DelegatedRefusal::NOT_AUTHORIZED);

        return match ($payload['operation']) {
            'capabilities' => ['controls' => ['application_admin' => true, 'workspace_roles' => [], 'provisioning' => true]],
            'subjects' => $this->subjects($actorSubject, $payload),
            'workspaces' => ['workspaces' => [], 'next_cursor' => null],
            'read' => $this->state($actor, (string) $payload['subject'], $this->bound((string) $payload['subject'])),
            'update' => $this->update($actor, $payload),
            default => throw DelegatedRefusal::of(DelegatedRefusal::INVALID_REQUEST),
        };
    }

    /**
     * The account bound to this subject, if it is an administrator who can sign in.
     *
     * @param  Collection<int, User>|null  $locked
     */
    private function administrator(string $subject, ?Collection $locked = null): ?User
    {
        $user = $locked !== null
            ? $locked->first(fn (User $u): bool => $u->oauth_provider === $this->issuer() && $u->oauth_subject === $subject)
            : $this->bound($subject);

        return $user !== null && $this->canSignIn($user) && $user->hasRole('admin') ? $user : null;
    }

    private function bound(string $subject, bool $lock = false): ?User
    {
        $query = User::query()->where('oauth_provider', $this->issuer())->where('oauth_subject', $subject);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function issuer(): string
    {
        $issuer = $this->settings->bindingIssuer();

        // The endpoint refuses a configuration without one before the adapter runs; this keeps an
        // empty name from ever matching rows.
        return $issuer !== '' ? $issuer : throw DelegatedRefusal::of(DelegatedRefusal::NOT_AUTHORIZED);
    }

    /**
     * Sign-in is through the provider only, so an account can sign in when its role allows it and
     * it is bound to the provider's subject.
     */
    private function canSignIn(User $user): bool
    {
        return $user->canLogin() && $user->oauth_provider === $this->issuer() && is_string($user->oauth_subject) && $user->oauth_subject !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function subjects(string $actorSubject, array $payload): array
    {
        $after = $this->cursor->after($actorSubject, 'subjects', $payload);
        $limit = (int) ($payload['limit'] ?? self::PAGE_LIMIT);

        $rows = User::query()
            ->where('oauth_provider', $this->issuer())
            ->whereNotNull('oauth_subject')
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'name', 'oauth_subject']);

        $page = $rows->take($limit);

        return [
            'subjects' => $page->map(static fn (User $user): array => [
                'subject' => (string) $user->oauth_subject,
                'label' => self::label($user),
            ])->values()->all(),
            'next_cursor' => $rows->count() > $limit ? $this->cursor->encode($actorSubject, 'subjects', (int) $page->last()->id) : null,
        ];
    }

    private static function label(User $user): string
    {
        $name = trim((string) $user->name);

        return mb_strcut($name !== '' ? $name : (string) $user->oauth_subject, 0, 255, 'UTF-8');
    }

    /**
     * @param  Collection<int, User>|null  $locked
     * @return array<string, mixed>
     */
    private function state(User $actor, string $subject, ?User $target, ?Collection $locked = null): array
    {
        if ($target === null) {
            return [
                'subject' => $subject,
                'provisioned' => false,
                'revision' => null,
                'access' => null,
                'allowed_edits' => ['application_admin' => false, 'workspaces' => false, 'provision' => true],
            ];
        }

        return [
            'subject' => $subject,
            'provisioned' => true,
            'revision' => self::revision($target),
            'access' => ['application_admin' => $target->hasRole('admin'), 'workspaces' => []],
            'allowed_edits' => [
                'application_admin' => $this->adminChangeRefusal($actor, $target, $locked) === null,
                'workspaces' => false,
                'provision' => false,
            ],
        ];
    }

    /**
     * Everything an update can change or depends on, so a change by any path moves the revision.
     */
    private static function revision(User $user): string
    {
        return hash('sha256', json_encode([$user->id, $user->oauth_provider, $user->oauth_subject, $user->getRawOriginal('user_role')], JSON_THROW_ON_ERROR));
    }

    /**
     * The one rule for whether the actor may change the target's administrator role. Null when
     * they may; otherwise why not. `$locked` is the locked row set during an update, so the rule is
     * decided on the rows the change takes.
     *
     * @param  Collection<int, User>|null  $locked
     */
    private function adminChangeRefusal(User $actor, User $target, ?Collection $locked = null): ?string
    {
        if ($target->id === 1) {
            return 'protected_account';
        }
        if ($target->id === $actor->id) {
            return 'own_account';
        }
        if (! $this->canSignIn($target)) {
            return 'cannot_sign_in';
        }
        if ($target->hasRole('admin') && ! $this->anotherAdministratorCanSignIn($target, $locked)) {
            return 'last_administrator';
        }

        return null;
    }

    /**
     * @param  Collection<int, User>|null  $locked
     */
    private function anotherAdministratorCanSignIn(User $target, ?Collection $locked): bool
    {
        $administrators = $locked ?? $this->administratorCandidates()->get();

        return $administrators->contains(fn (User $user): bool => $user->id !== $target->id && $user->hasRole('admin') && $this->canSignIn($user));
    }

    /**
     * Every row that might be an administrator: account #1, and any whose roles mention admin.
     * Filtered precisely with User::hasRole() by the caller.
     *
     * @return Builder<User>
     */
    private function administratorCandidates(): Builder
    {
        return User::query()->where(static fn ($query) => $query->where('id', 1)->orWhereRaw('LOWER(user_role) LIKE ?', ['%admin%']));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function update(User $actor, array $payload): array
    {
        $subject = (string) $payload['subject'];
        $access = $payload['access'];

        // Account-only: there are no workspaces to hold a membership in.
        if ($access['workspaces'] !== []) {
            throw DelegatedRefusal::of(DelegatedRefusal::INVALID_REQUEST);
        }

        if ($payload['expected_revision'] === null) {
            return $this->provision($actor, $subject, (bool) $access['application_admin'], $payload['display_name'] ?? null);
        }

        return DB::transaction(function () use ($actor, $subject, $access, $payload): array {
            $locked = $this->lockAccounts($actor, $subject);

            // The actor's own authority is re-read on the locked rows: a concurrent change may have
            // removed it since the request was authorized.
            $actor = $this->administrator((string) $actor->oauth_subject, $locked) ?? throw DelegatedRefusal::of(DelegatedRefusal::NOT_AUTHORIZED);
            $target = $locked->first(fn (User $u): bool => $u->oauth_provider === $this->issuer() && $u->oauth_subject === $subject);

            // An update with a revision names a provisioned subject; if it is not, the provider's view
            // is stale and a fresh read shows it unprovisioned.
            if ($target === null || ! hash_equals(self::revision($target), (string) $payload['expected_revision'])) {
                throw DelegatedRefusal::of(DelegatedRefusal::REVISION_CONFLICT);
            }

            $wanted = (bool) $access['application_admin'];
            if ($wanted === $target->hasRole('admin')) {
                return $this->state($actor, $subject, $target, $locked);
            }

            if ($this->adminChangeRefusal($actor, $target, $locked) !== null) {
                throw DelegatedRefusal::of(DelegatedRefusal::NOT_AUTHORIZED);
            }

            $target->forceFill(['user_role' => self::rolesWithAdmin((string) $target->getRawOriginal('user_role'), $wanted)])->save();
            $this->audit($wanted ? self::EVENT_ADMIN_GRANTED : self::EVENT_ADMIN_REVOKED, $actor, $target);

            $target = User::query()->findOrFail($target->id);
            $locked = $locked->map(static fn (User $u): User => $u->id === $target->id ? $target : $u);

            return $this->state($actor, $subject, $target, $locked);
        });
    }

    /**
     * Lock, in id order, the actor, the target and every possible administrator, so the revision,
     * the actor's authority and the last-administrator rule are all read from rows no concurrent
     * update can change underneath.
     *
     * @return Collection<int, User>
     */
    private function lockAccounts(User $actor, string $subject): Collection
    {
        return $this->administratorCandidates()
            ->orWhere('id', $actor->id)
            ->orWhere(fn ($query) => $query->where('oauth_provider', $this->issuer())->where('oauth_subject', $subject))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * The role list with `admin` added or removed, every other entry kept as it was.
     */
    public static function rolesWithAdmin(string $raw, bool $admin): string
    {
        $others = array_values(array_filter(
            explode(',', $raw),
            static fn (string $role): bool => trim($role) !== '' && strtolower(trim($role)) !== 'admin',
        ));

        if ($admin) {
            $others[] = 'admin';
        } elseif (! in_array('user', array_map(static fn (string $role): string => strtolower(trim($role)), $others), true)) {
            // `admin` was what let this account sign in; demotion leaves an ordinary account.
            $others[] = 'user';
        }

        return implode(',', $others);
    }

    /**
     * Create an account bound to the provider's issuer and this exact subject. Never adopts an
     * existing row: there is no lookup by name or address, and a subject that is already bound,
     * or a placeholder address already taken, is a conflict. The first sign-in replaces the
     * placeholder name and address with the provider's (OAuthLoginController resolves by subject).
     *
     * @return array<string, mixed>
     */
    private function provision(User $actor, string $subject, bool $admin, mixed $displayName): array
    {
        $issuer = $this->issuer();

        try {
            return DB::transaction(function () use ($actor, $subject, $admin, $displayName, $issuer): array {
                $actor = $this->administrator((string) $actor->oauth_subject, User::query()->whereKey($actor->id)->lockForUpdate()->get())
                    ?? throw DelegatedRefusal::of(DelegatedRefusal::NOT_AUTHORIZED);

                if ($this->bound($subject, lock: true) !== null) {
                    throw DelegatedRefusal::of(DelegatedRefusal::REVISION_CONFLICT);
                }

                $label = is_string($displayName) && trim($displayName) !== '' ? trim($displayName) : 'Invited account';
                $user = User::query()->forceCreate([
                    'name' => PendingAccount::name($label, $subject),
                    'email' => PendingAccount::email($issuer, $subject),
                    'email_verified_at' => null,
                    'password' => Hash::make(Str::random(64)),
                    'user_role' => $admin ? 'user,admin' : 'user',
                    'oauth_provider' => $issuer,
                    'oauth_subject' => $subject,
                ]);
                $this->audit(self::EVENT_PROVISIONED, $actor, $user, ['application_admin' => $admin]);

                return $this->state($actor, $subject, User::query()->findOrFail($user->id));
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent provisioning of the same subject, or a placeholder address already held
            // by another row. Either way nothing is adopted.
            throw DelegatedRefusal::of(DelegatedRefusal::REVISION_CONFLICT);
        }
    }

    /**
     * Recorded in the same transaction as the change, so a change and its audit commit together,
     * with the request's `jti` to correlate with the provider's own records.
     *
     * @param  array<string, mixed>  $extra
     */
    private function audit(string $event, User $actor, User $target, array $extra = []): void
    {
        $context = $this->container->bound(DelegatedRequestContext::class) ? $this->container->make(DelegatedRequestContext::class) : null;
        $request = $this->container->bound('request') ? $this->container->make('request') : null;

        AuthAuditLog::query()->create([
            'user_id' => $target->id,
            'acting_user_id' => $actor->id,
            'email' => $target->email,
            'event' => $event,
            'auth_method' => 'delegated_access',
            'succeeded' => true,
            'ip_address' => $request?->ip(),
            'metadata' => [
                'jti' => $context?->jti,
                'issuer' => $context?->issuer,
                'application' => $context?->application,
                'subject' => $target->oauth_subject,
                ...$extra,
            ],
        ]);
    }
}
