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

    def test_partial_rsync_transfer_keeps_nonce_owned_cleanup_recoverable(self):
        with tempfile.TemporaryDirectory() as directory:
            run = Path(directory)
            fake = run / "fake-ssh"
            fake.write_text('''#!/usr/bin/env python3
import json, subprocess, sys
payload = sys.stdin.read()
if "# Read only fixed runtime prerequisites" in payload:
    print(json.dumps({"stage":"prerequisites","status":"ready","code":"ready",
        "php_version":"8.5.12","legacy_roots":0,"legacy_window_matches":0}))
else:
    sys.exit(subprocess.run(["bash", "-c", sys.argv[-1]], input=payload, text=True).returncode)
''')
            fake.chmod(0o700)
            rsync = run / "fake-rsync"
            rsync.write_text('''#!/usr/bin/env python3
from pathlib import Path
import sys
root = Path(sys.argv[-1].split(":", 1)[1])
(root / "application").mkdir()
(root / "application/partial").write_text("synthetic partial transfer")
sys.exit(12)
''')
            rsync.chmod(0o700)
            (run / "ehi-host-payload").mkdir()
            (run / "ehi-host-payload/tree-manifest.json").write_text("synthetic")
            env = os.environ | {"CANDIDATE": "a" * 40, "RUNNER_TEMP": str(run),
                                "GITHUB_STEP_SUMMARY": str(run / "summary"), "EHI_HOST_SSH_BIN": str(fake),
                                "EHI_HOST_RSYNC_BIN": str(rsync)}
            result = subprocess.run(["bash", str(HERE / "run-host-calibration.sh")], cwd=run, env=env, capture_output=True)
            root, nonce = (run / "ehi-host-owned-root").read_text().splitlines()
            try:
                self.assertNotEqual(0, result.returncode)
                self.assertTrue((Path(root) / "application/partial").exists())
                self.assertIn(b"transport_failed", result.stdout)
            finally:
                subprocess.run(["bash", str(HERE / "cleanup-host-root.sh"), root, nonce], capture_output=True, check=True)
                self.assertFalse(Path(root).exists())

    def test_foreign_runtime_lock_or_candidate_cannot_be_tagged_as_expected_source(self):
        fixture_spec = importlib.util.spec_from_file_location("capacity_fixture", HERE.parent / "test-ehi-capacity.py")
        fixture = importlib.util.module_from_spec(fixture_spec)
        fixture_spec.loader.exec_module(fixture)
        case = fixture.CapacityObservationTest()
        runtime_spec = importlib.util.spec_from_file_location("runtime", HERE / "validate-host-runtime.py")
        runtime = importlib.util.module_from_spec(runtime_spec)
        runtime_spec.loader.exec_module(runtime)
        preview = case.result() | {"history_records": 2000, "clinical_lab_rows": 2000}
        lifecycle = case.lifecycle() | {"history_records": 2000}
        lifecycle["lifecycle"]["review_state"]["phr_lab_results"] = 2001
        for row in (preview, lifecycle):
            row.update({"composer_lock_sha256": "c" * 64, "php_version": "8.5.12", "max_rss_kib": 1000})
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            manifest = root / "manifest"
            manifest.write_text(json.dumps({"candidate": "a" * 40, "lock_sha256": "c" * 64, "bundle_size": {}}))
            raw = root / "raw"
            for change in ({}, {"composer_lock_sha256": "d" * 64}, {"candidate": "b" * 40}):
                raw.write_text("\n".join(json.dumps(row) for row in
                    (preview | change, lifecycle, {"cleanup": "confirmed", "scope": "owned-synthetic-root"})))
                output = root / "published"
                output.unlink(missing_ok=True)
                with contextlib.redirect_stdout(io.StringIO()):
                    success = runtime.publish(raw, "a" * 40, 0, output, root / "summary", manifest)
                self.assertEqual(not change, success)
                self.assertEqual(not change, output.exists())

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
