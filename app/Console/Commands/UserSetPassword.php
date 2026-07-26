<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Set a user's password from the console.
 *
 * This replaces the former `/login/dev` and `/login/dev-by-id` HTTP routes,
 * which logged the caller in as any user with no password and were gated only
 * by `APP_ENV`/`APP_URL`. A misconfigured environment turned either one into a
 * public login-as-anyone endpoint, so the affordance now lives behind shell
 * access — which is already full compromise — rather than behind a config flag
 * on a routable URL.
 */
class UserSetPassword extends Command
{
    protected $signature = 'user:set-password
                            {email : Email address of the user to update}
                            {--password= : Password to set; omit to generate a random one}';

    protected $description = "Set a user's login password (local development and account recovery).";

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with email [{$email}].");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?? '');
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(24);
        }

        $user->forceFill(['password' => $password])->save();

        $this->info("Password updated for {$user->email} (id={$user->id}).");

        if ($generated) {
            $this->line('Generated password: '.$password);
        }

        if (! $user->canLogin()) {
            $this->warn("This account cannot sign in: user_role is [{$user->getRawOriginal('user_role')}], which grants neither 'user' nor 'admin'.");
        }

        return self::SUCCESS;
    }
}
