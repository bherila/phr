<?php

namespace App\Console\Commands\Phr;

use App\Models\AgentApiAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:agent-api:prune-audits')]
#[Description('Delete expired metadata-only agent API request audits')]
final class AgentApiPruneAuditsCommand extends BasePhrCommand
{
    public function handle(): int
    {
        $days = max(1, (int) config('agent_api.audit_retention_days', 365));
        $deleted = AgentApiAudit::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} agent API audit(s).");

        return self::SUCCESS;
    }
}
