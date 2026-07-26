<?php

namespace App\GenAiProcessor\Services\Prompts;

/**
 * Base class for GenAI prompt templates.
 *
 * Each job type has its own subclass that implements `build()`.
 * Shared helpers (accounts context, structured-output instructions) live here
 * so they are not duplicated across templates.
 */
abstract class PromptTemplate
{
    /**
     * Build the prompt string for the given context.
     *
     * @param  array<string,mixed>  $context
     */
    abstract public function build(array $context): string;

    /**
     * Build the accounts context block to embed in prompts.
     *
     * Only includes account name and last 4 digits — never the full account number.
     *
     * @param  array<array{name:string,last4:string}>  $accounts
     */
    protected function buildAccountsContext(array $accounts): string
    {
        if (empty($accounts)) {
            return '';
        }

        $lines = array_map(
            fn ($a) => "- {$a['name']}: last 4 digits {$a['last4']}",
            $accounts
        );

        return "\n\nKnown user accounts (use these to assign transactions to the correct account):\n".implode("\n", $lines);
    }
}
