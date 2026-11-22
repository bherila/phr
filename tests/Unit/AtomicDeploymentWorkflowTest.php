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

        $this->assertMatchesRegularExpression(
            '/bherila\/shared-cpanel-deployment@[a-f0-9]{40}(?:\s|$)/',
            $workflow,
        );
        $this->assertStringContainsString('atomic-layout: stable-directory', $workflow);
        $this->assertSame(1, preg_match_all('/^          runtime-audit: true$/m', $workflow));
        $this->assertStringContainsString('post-finalize-script: .github/scripts/verify-phr-finalized.sh', $workflow);
        $this->assertStringContainsString('initial-live-commit: ${{ vars.ATOMIC_INITIAL_LIVE_COMMIT }}', $workflow);
        $this->assertStringContainsString('recovery-release-id: ${{ vars.ATOMIC_RECOVERY_RELEASE_ID }}', $workflow);
        $this->assertStringContainsString('failure-policy: rollback', $workflow);
        $this->assertSame(1, preg_match_all('/^          operational-audit: true$/m', $workflow));
        $this->assertStringContainsString("vars.ATOMIC_DEPLOY_ENABLED != 'false'", $workflow);
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

    /**
     * Once the provider identity cutover is recorded, enforcement stays on whatever the setting
     * says. A release whose configuration disagrees is stopped before selection (the candidate
     * hook) and again on the stable path's cached configuration while it is still in maintenance
     * (the post-activation hook), where a failure rolls the deployment back instead of serving it.
     */
    public function test_both_release_gates_run_the_provider_identity_cutover_check(): void
    {
        $check = 'artisan bherila-auth:provider-identity-cutover check --no-ansi';
        foreach (['verify-phr-candidate.sh', 'verify-phr-activation.sh'] as $hook) {
            $script = file_get_contents(base_path(".github/scripts/{$hook}"));
            $this->assertIsString($script);
            $this->assertStringContainsString("\nset -euo pipefail\n", $script, "{$hook} must stop on the first failing gate.");
            $this->assertSame(1, substr_count($script, $check), "{$hook} must run the cutover check exactly once.");
            $this->assertStringNotContainsString($check.' ||', $script, "{$hook} must not swallow a failed cutover check.");
        }

        $candidate = (string) file_get_contents(base_path('.github/scripts/verify-phr-candidate.sh'));
        $this->assertLessThan(strpos($candidate, "echo 'PHR candidate schedule"), strpos($candidate, $check), 'The candidate is reported valid only after the check passes.');

        $activation = (string) file_get_contents(base_path('.github/scripts/verify-phr-activation.sh'));
        $this->assertLessThan(strpos($activation, $check), strpos($activation, 'artisan env --no-ansi'), 'The check reads the selected release, once it boots in production.');

        $workflow = $this->workflow('ci.yml');
        $this->assertStringContainsString('failure-policy: rollback', $workflow);
        $this->assertStringContainsString('post-deploy-script: .github/scripts/verify-phr-candidate.sh', $workflow);
        $this->assertStringContainsString('post-activate-script: .github/scripts/verify-phr-activation.sh', $workflow);
    }

    public function test_production_writers_gate_sources_and_publish_exact_artifact_identities(): void
    {
        $ci = $this->workflow('ci.yml');
        $ohif = $this->workflow('ohif-dist.yml');
        $this->assertStringContainsString('fetch-depth: 0', $ci);
        $this->assertStringContainsString('node .github/scripts/deployment-policy.mjs app', $ci);
        $this->assertStringContainsString("if: \${{ steps.app-plan.outputs.proceed == 'true' }}", $ci);
        foreach ([$ci, $ohif] as $workflow) {
            $this->assertStringContainsString('node .github/scripts/deployment-policy.mjs ohif', $workflow);
            $this->assertSame(1, preg_match('/^  deploy:\R.*?(?=^  [a-zA-Z0-9_-]+:|\z)/ms', $workflow, $deployment));
            $this->assertStringContainsString('secrets.PHR_PRODUCTION_SSH_KEY', $deployment[0]);
            $this->assertStringNotContainsString('secrets.SSH_PRIVATE_KEY', $deployment[0]);
            $this->assertStringContainsString('OHIF_ARTIFACT_ID: ${{ steps.ohif.outputs.artifact_id }}', $workflow);
            $this->assertStringContainsString('OHIF_ARTIFACT_DIGEST: ${{ steps.ohif.outputs.artifact_digest }}', $workflow);
            $this->assertStringNotContainsString('run: bash .github/scripts/converge-ohif-dist.sh', $workflow);
        }
        $this->assertStringContainsString('OHIF_LOCK_OWNER="$DEPLOY_RELEASE_ID"', file_get_contents(base_path('.github/scripts/verify-phr-deployment.sh')));
        $this->assertStringContainsString('workflow_run:', $this->workflow('deployment-audit.yml'));
        $this->assertStringContainsString('retention-days: 90', $this->workflow('deployment-audit.yml'));
    }

    public function test_temporary_incident_dispatch_surface_is_removed(): void
    {
        $workflow = $this->workflow('ci.yml');

        $this->assertStringContainsString('workflow_dispatch:', $workflow);
        $this->assertStringNotContainsString('inputs.operation', $workflow);
        $this->assertStringNotContainsString('inputs.confirmation', $workflow);
        foreach (['diagnose-phr-orphan', 'recover-phr-orphan', 'verify-phr-orphan-public'] as $script) {
            $this->assertStringNotContainsString($script, $workflow);
            $this->assertFileDoesNotExist(base_path(".github/scripts/{$script}.sh"));
            $this->assertFileDoesNotExist(base_path(".github/scripts/{$script}.test.sh"));
        }
    }

    public function test_candidate_upload_includes_the_required_secret_installer_and_application_paths(): void
    {
        $workflow = $this->workflow('ci.yml');
        $this->assertSame(1, preg_match('/^          paths: \|\R((?:            [^\r\n]+\R)+)/m', $workflow, $matches));
        $paths = array_map('trim', explode("\n", trim($matches[1])));

        foreach (['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'storage', 'vendor', 'artisan', 'composer.json', 'composer.lock'] as $path) {
            $this->assertContains($path, $paths, "Candidate upload lost standard application path {$path}.");
        }

        $installer = 'scripts/configure-agent-mutation-digest-key.php';
        $this->assertFileExists(base_path($installer));
        $this->assertContains(dirname($installer), $paths, 'Candidate secret provisioning requires the installer in the uploaded release.');
        $this->assertStringContainsString('$candidate_root/'.$installer, file_get_contents(base_path('.github/scripts/provision-phr-candidate-secrets.sh')));
    }
}
