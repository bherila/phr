<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Revoke a user's MCP bearer token.
 *
 * Revocation takes effect on the next request: the middleware resolves the
 * token by hash on every call, so there is no cached grant to outlive this.
 */
class McpTokenRevoke extends Command
{
    protected $signature = 'mcp:token:revoke
                            {email : Email address of the user whose token should be revoked}';

    protected $description = 'Revoke a user\'s MCP bearer token immediately.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with email [{$email}].");

            return self::FAILURE;
        }

        if ($user->mcp_api_key === null) {
            $this->line("[{$email}] has no MCP token; nothing to revoke.");

            return self::SUCCESS;
        }

        $lastUsed = $user->mcp_api_key_last_used_at?->toDateTimeString() ?? 'never';
        $user->revokeMcpToken();

        $this->info("Revoked the MCP token for {$user->email} (id={$user->id}). Last used: {$lastUsed}.");

        return self::SUCCESS;
    }
}
