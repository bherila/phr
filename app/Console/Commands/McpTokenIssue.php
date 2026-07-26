<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Issue an MCP bearer token for a user's Sinus Sentinel device.
 *
 * The token grants access to the respiratory-event and sinus routes as that
 * user, so it is treated like a password: generated here, shown once, stored
 * only as a SHA-256 hash, and given a fixed expiry.
 */
class McpTokenIssue extends Command
{
    protected $signature = 'mcp:token:issue
                            {email : Email address of the user to issue a token for}
                            {--days= : Token lifetime in days (default '.User::MCP_TOKEN_DEFAULT_DAYS.')}';

    protected $description = 'Issue an MCP bearer token, replacing any existing one for that user.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with email [{$email}].");

            return self::FAILURE;
        }

        if (! $user->canLogin()) {
            $this->error("[{$email}] cannot sign in, so a token would be useless. Grant the 'user' or 'admin' role first.");

            return self::FAILURE;
        }

        $days = $this->option('days');
        $days = $days === null ? User::MCP_TOKEN_DEFAULT_DAYS : (int) $days;

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $hadToken = $user->mcp_api_key !== null;
        $token = $user->issueMcpToken($days);

        if ($hadToken) {
            $this->warn('The previous token for this user has been invalidated.');
        }

        $this->newLine();
        $this->info("MCP token for {$user->email} (id={$user->id}):");
        $this->line($token);
        $this->newLine();
        $this->line('Expires: '.$user->mcp_api_key_expires_at?->toDateTimeString().sprintf(' (%d days)', $days));
        $this->comment('This is the only time the token is shown. Store it now; only its hash is kept.');
        $this->comment('Send it as: Authorization: Bearer <token>');

        return self::SUCCESS;
    }
}
