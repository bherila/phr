#!/usr/bin/env python3
"""Short transport/ownership regressions; never contact a deployment host."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("stage", HERE / "validate-host-stage.py")
stage = importlib.util.module_from_spec(spec)
spec.loader.exec_module(stage)


class PreflightTest(unittest.TestCase):
    def test_prerequisite_refusal_and_unknown_ssh_output_are_sanitized(self):
        with tempfile.TemporaryDirectory() as directory:
            raw = Path(directory) / "raw"
            proof = {"stage": "prerequisites", "status": "refused", "code": "php_binary_missing",
                     "php_version": "8.5.12", "legacy_roots": 0, "legacy_window_matches": 0}
            raw.write_text(json.dumps(proof))
            self.assertEqual(proof, stage.validate(raw, "prerequisites", 1))
            for value in ("PRIVATE_BANNER", "", json.dumps(proof | {"raw_error": "PRIVATE"})):
                raw.write_text(value)
                result = stage.validate(raw, "prerequisites", 255)
                self.assertIn(result["code"], ("invalid_output", "transport_failed"))
                self.assertNotIn("PRIVATE", json.dumps(result))

    def test_wrong_nonce_and_preexisting_root_never_overwrite_or_delete(self):
        root = Path("/tmp/phr-calibration." + secrets.token_hex(5))
        nonce = secrets.token_hex(32)
        try:
            created = subprocess.run(["bash", str(HERE / "create-host-root.sh"), str(root), nonce], capture_output=True)
            self.assertEqual(0, created.returncode)
            marker = (root / ".owned-synthetic-calibration").read_text()
            wrong = secrets.token_hex(32)
            refused = subprocess.run(["bash", str(HERE / "create-host-root.sh"), str(root), wrong], capture_output=True)
            self.assertNotEqual(0, refused.returncode)
            self.assertEqual(marker, (root / ".owned-synthetic-calibration").read_text())
            cleanup = subprocess.run(["bash", str(HERE / "cleanup-host-root.sh"), str(root), wrong], capture_output=True)
            self.assertNotEqual(0, cleanup.returncode)
            self.assertTrue(root.exists())
        finally:
            subprocess.run(["bash", str(HERE / "cleanup-host-root.sh"), str(root), nonce], capture_output=True, check=True)

    def test_connection_loss_after_remote_creation_has_pre_recorded_cleanup_identity(self):
        with tempfile.TemporaryDirectory() as directory:
            run = Path(directory)
            fake = run / "fake-ssh"
            fake.write_text('''#!/usr/bin/env python3
import json, subprocess, sys
payload = sys.stdin.read()
if "# Read only fixed runtime prerequisites" in payload:
    print(json.dumps({"stage":"prerequisites","status":"ready","code":"ready","php_version":"8.5.12","legacy_roots":0,"legacy_window_matches":0}))
else:
    result = subprocess.run(["bash", "-c", sys.argv[-1]], input=payload, text=True, capture_output=True)
    sys.stdout.write(result.stdout)
    sys.exit(255) # Connection lost AFTER successful remote root creation.
''')
            fake.chmod(0o700)
            env = os.environ | {"CANDIDATE": "a" * 40, "RUNNER_TEMP": str(run),
                                "GITHUB_STEP_SUMMARY": str(run / "summary"), "EHI_HOST_SSH_BIN": str(fake)}
            result = subprocess.run(["bash", str(HERE / "run-host-calibration.sh")], cwd=run, env=env, capture_output=True)
            root, nonce = (run / "ehi-host-owned-root").read_text().splitlines()
            try:
                self.assertNotEqual(0, result.returncode)
                self.assertTrue(Path(root).exists())
                self.assertNotIn(root.encode(), result.stdout)
                self.assertIn(b"transport_failed", result.stdout)
            finally:
                subprocess.run(["bash", str(HERE / "cleanup-host-root.sh"), root, nonce], capture_output=True, check=True)
                self.assertFalse(Path(root).exists())

    def test_runtime_banner_is_never_published(self):
        spec = importlib.util.spec_from_file_location("runtime", HERE / "validate-host-runtime.py")
        runtime = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(runtime)
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            raw = root / "raw"
            raw.write_text("PRIVATE_BANNER\n")
            out = io.StringIO()
            with contextlib.redirect_stdout(out):
                self.assertFalse(runtime.publish(raw, "a" * 40, 1, root / "published", root / "summary"))
            self.assertNotIn("PRIVATE", out.getvalue())
            self.assertFalse((root / "published").exists())


if __name__ == "__main__":
    unittest.main()
