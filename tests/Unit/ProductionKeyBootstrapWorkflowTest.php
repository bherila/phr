<?php

namespace Tests\Unit;

use Tests\TestCase;

class ProductionKeyBootstrapWorkflowTest extends TestCase
{
    public function test_key_bootstrap_is_manual_protected_and_serialized_with_production_writers(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/production-key-bootstrap.yml'));
        $this->assertStringContainsString("github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'", $workflow);
        $this->assertStringContainsString('environment: prod', $workflow);
        $this->assertStringContainsString('group: phr-production-files', $workflow);
        $this->assertStringContainsString('cancel-in-progress: false', $workflow);
        $this->assertStringContainsString('queue: max', $workflow);
        $this->assertStringContainsString('bash .github/scripts/install-phr-production-key.test.sh', $workflow);
        $this->assertStringContainsString('395b0d8b10db1181e50f4eec27d7bce27b1522d6', $workflow);
        $this->assertStringContainsString('test "$EXPECTED_RELEASE" = 7b33fe7445aa-37239370303-1', $workflow);
        $this->assertStringContainsString('test "$EXPECTED_COMMIT" = 7b33fe7445aa6d0a002f2e752497282b04c360a2', $workflow);
        $this->assertStringNotContainsString('actions/upload-artifact', $workflow);
    }

    public function test_dedicated_key_proof_cannot_reuse_legacy_authentication(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/production-key-bootstrap.yml'));
        $dedicated = substr($workflow, strpos($workflow, '- name: Configure the dedicated credential'));
        $this->assertStringContainsString('secrets.PHR_PRODUCTION_SSH_KEY', $dedicated);
        $this->assertStringNotContainsString('secrets.SSH_PRIVATE_KEY', $dedicated);
        $this->assertStringContainsString('ControlMaster no', $dedicated);
        $this->assertStringContainsString('ControlPath none', $dedicated);
        $this->assertStringContainsString('IdentityAgent none', $dedicated);
        $this->assertStringContainsString('ssh-keygen -y -f "$HOME/.ssh/phr-policy-verify.key"', $dedicated);
        $this->assertStringContainsString('test "$actual" = "$expected"', $dedicated);
        $this->assertStringContainsString('DIAG_SSH_TARGET: phr-policy-verify', $dedicated);
        $this->assertStringContainsString('bash .github/scripts/diagnose-phr-maintenance.sh', $dedicated);
    }
}
