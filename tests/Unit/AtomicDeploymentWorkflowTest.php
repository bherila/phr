<?php

namespace Tests\Unit;

use Tests\TestCase;

class AtomicDeploymentWorkflowTest extends TestCase
{
    private function workflow(string $name): string
    {
        $contents = file_get_contents(base_path(".github/workflows/{$name}"));
        $this->assertIsString($contents);

        return $contents;
    }

    public function test_main_and_ohif_deployments_share_a_non_cancelling_production_lock(): void
    {
        $ci = $this->workflow('ci.yml');
        $ohif = $this->workflow('ohif-dist.yml');

        foreach ([$ci, $ohif] as $workflow) {
            $this->assertMatchesRegularExpression(
                '/group: phr-production-files\R\s+cancel-in-progress: false/',
                $workflow,
            );
        }

        $this->assertStringContainsString(
            "cancel-in-progress: \${{ github.ref != 'refs/heads/main' }}",
            $ci,
        );
    }

    public function test_atomic_deploy_declares_persistence_quiescence_and_live_verification(): void
    {
        $workflow = $this->workflow('ci.yml');

        $this->assertStringContainsString(
            'bherila/shared-cpanel-deployment@e97463f105268c9f63449cf5b6730c95386f709d',
            $workflow,
        );
        $this->assertStringContainsString('atomic-layout: stable-directory', $workflow);
        $this->assertStringContainsString('initial-live-commit: ${{ vars.ATOMIC_INITIAL_LIVE_COMMIT }}', $workflow);
        $this->assertStringContainsString('recovery-release-id: ${{ vars.ATOMIC_RECOVERY_RELEASE_ID }}', $workflow);
        $this->assertStringContainsString('failure-policy: maintenance', $workflow);
        $this->assertMatchesRegularExpression('/persistent-paths:\s*\|\R\s+storage\R\s+public\/ohif/', $workflow);
        $this->assertStringContainsString('quiesce-script: .github/scripts/quiesce-phr-deployment.sh', $workflow);
        $this->assertStringContainsString('pre-migrate-script: .github/scripts/provision-phr-candidate-secrets.sh', $workflow);
        $this->assertStringContainsString('post-deploy-script: .github/scripts/verify-phr-candidate.sh', $workflow);
        $this->assertStringContainsString('post-activate-script: .github/scripts/verify-phr-activation.sh', $workflow);
        $this->assertStringContainsString('verification-script: .github/scripts/verify-phr-deployment.sh', $workflow);
        $this->assertStringContainsString('install-cron: true', $workflow);
        $this->assertStringContainsString('# JOB:phr-laravel-scheduler', $workflow);
        $this->assertStringContainsString('# JOB:phr-laravel-queue-worker', $workflow);
        $this->assertStringNotContainsString('post-deploy-script: .github/scripts/configure-phr-scheduler.sh', $workflow);
        $this->assertStringNotContainsString('PHR_ENV_FILE=\$HOME/phr-laravel/.env', $workflow);
        $this->assertStringNotContainsString('Create or verify persistent agent mutation digest key', $workflow);
    }
}
