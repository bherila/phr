#!/usr/bin/env python3
"""Small result-contract tests; realistic profiles are CI-only."""
import copy
import contextlib
import io
import json
import os
import importlib.util
from pathlib import Path
import runpy
import unittest
import tempfile
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("qualify", Path(__file__).with_name("assert-ehi-qualification.py"))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class QualificationTest(unittest.TestCase):
    def test_experimental_pairs_preserve_declared_lifecycles_and_control_limits(self):
        profiles = json.loads(Path(__file__).with_name("ehi-qualification-profiles.json").read_text())
        envelopes = json.loads(Path(__file__).with_name("ehi-qualification-envelopes.json").read_text())
        self.assertEqual({"rows": 10000, "bytes": 8388608, "native_records": 20000, "native_stream_bytes": 33554432}, envelopes["default"])
        self.assertEqual({"rows": 50000, "bytes": 67108864, "native_records": 50000, "native_stream_bytes": 67108864}, envelopes["expanded"])
        for tier, rows, size in [("100k", 100000, 128 * 1024**2), ("150k", 150000, 192 * 1024**2)]:
            self.assertEqual({"rows": rows, "bytes": size, "native_records": rows, "native_stream_bytes": size}, envelopes["paired-" + tier])
            for name in ["target-2000", "three-cycles", "stretch-full"]:
                baseline = profiles[name]
                candidate = profiles[name + "-" + tier]
                self.assertEqual(name, candidate["baseline_profile"])
                for key in baseline.keys() - {"envelope", "forecast"}:
                    self.assertEqual(baseline[key], candidate[key], (name, key))
                self.assertEqual("experimental", candidate["expectation"])

    def completed(self):
        return {"profile": "synthetic", "status": "completed", "profile_wall_seconds": 1, "operation_ceiling_seconds": 300,
                "sampled_rss_kib": 1000, "declaration": {"expectation": "must_complete", "sharing": True, "review": "full", "exercise": False},
                "lifecycle": {"round_trip_verified": True, "cleanup_verified": True, "independent_download_verified": True,
                    "access_semantics_verified": True, "existing_opt_in_shares_preserved": True, "new_patient_opt_in_blocked": True,
                    "review_state": {"proposal_statuses": {"accepted": 9, "rejected": 1}}, "verified_tables": 43, "verified_artifacts": 29}}

    def test_complete_requires_real_recovery_cleanup_access_and_full_review(self):
        self.assertIn("qualification_met=True", module.validate(self.completed()))
        for key in ("round_trip_verified", "cleanup_verified", "independent_download_verified", "access_semantics_verified", "existing_opt_in_shares_preserved"):
            result = self.completed()
            result["lifecycle"][key] = False
            with self.subTest(key=key), self.assertRaises(ValueError): module.validate(result)
        result = self.completed()
        result["lifecycle"]["review_state"]["proposal_statuses"]["pending"] = 1
        with self.assertRaises(ValueError): module.validate(result)

    def test_expected_refusal_is_not_capacity_success_and_wrong_stage_fails(self):
        result = self.completed()
        result.update({"status": "record_count_limit", "lifecycle": {"refused_stage": "backup"}})
        result["declaration"] |= {"expectation": "must_refuse_at_stage", "refused_stage": "backup", "refusal": "record_count_limit"}
        self.assertIn("qualification_met=False", module.validate(result))
        result["lifecycle"]["refused_stage"] = "restore_apply"
        with self.assertRaises(ValueError): module.validate(result)

    def test_experiment_timeout_is_unmet_and_invariant_failure_is_never_accepted(self):
        result = self.completed()
        result["declaration"]["expectation"] = "experimental"
        result["status"] = "timeout"
        result["lifecycle"] = {}
        self.assertIn("qualification_met=False", module.validate(result))
        result["status"] = "unexpected_failure"
        with self.assertRaises(ValueError): module.validate(result)

    def test_persisted_margin_is_not_admission_headroom_and_refusal_invents_no_archive(self):
        result = self.completed()
        result["limits"] = {"rows": 100, "bytes": 1000, "native_records": 200, "native_stream_bytes": 2000}
        result["stages"] = {"review": {"durable": {"rows": 130, "bytes": 900,
            "persisted_preview_work": {"rows": 110, "bytes": 800}, "tables": {}}}}
        result["lifecycle"].update({"archive_records": 130, "record_stream_bytes": 1500})
        module.validate(result)
        observation = result["capacity_observation"]
        self.assertEqual(-10, observation["persisted_preview_work"]["row_margin_upper_bound"])
        self.assertFalse(observation["persisted_preview_work"]["includes_in_flight_reservations"])
        self.assertEqual(70, observation["native"]["record_margin"])
        result.update({"status": "timeout", "lifecycle": {}, "profile_wall_seconds": 300.01})
        result["declaration"]["expectation"] = "experimental"
        module.validate(result)
        self.assertFalse(result["qualification_met"])
        self.assertIsNone(result["capacity_observation"]["native"])
        self.assertTrue(result["capacity_observation"]["persisted_preview_work"]["metrics_incomplete"])
        self.assertLess(result["capacity_observation"]["whole_profile_time_margin_seconds"], 0)

    def test_timeout_retains_active_aggregate_lower_bounds_without_a_real_process(self):
        observer_spec = importlib.util.spec_from_file_location("observer", Path(__file__).with_name("observe-phr-resources.py"))
        observer = importlib.util.module_from_spec(observer_spec)
        observer_spec.loader.exec_module(observer)
        class Running:
            pid = 999999999
            def poll(self): return None
            def wait(self): return -9
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "qualification-progress.json").write_text(json.dumps({"stages": {"review": {"queries": 20, "peak_php_bytes": 2000}}}))
            (root / "qualification-active.json").write_text(json.dumps({"stage": "cycle_2_preview", "seconds": 31,
                "queries": 90, "max_bindings": 500, "peak_php_bytes": 4000, "max_open_transaction_seconds": 30}))
            with patch.object(observer.subprocess, "Popen", return_value=Running()), patch.object(observer.time, "monotonic", side_effect=[0, 301, 301.1]), patch.object(observer.os, "killpg") as killed:
                result = observer.observe(["--qualification=synthetic", "--dir=" + directory], timeout_seconds=300)
            self.assertEqual("timeout", result["status"])
            self.assertEqual("cycle_2_preview", result["refused_stage"])
            self.assertTrue(result["active_stage_metrics"]["metrics_incomplete"])
            self.assertEqual(110, result["queries"])
            self.assertEqual(4000, result["peak_php_bytes"])
            killed.assert_called_once_with(Running.pid, observer.signal.SIGKILL)

    def test_runner_applies_declared_pair_and_refuses_different_runtime_limits(self):
        class Finished:
            returncode = 0
            def poll(self): return 0
            def wait(self): return 0
        limits = {"rows": 150000, "bytes": 192 * 1024**2, "native_records": 150000,
                  "native_stream_bytes": 192 * 1024**2, "seconds": 240}
        def completed_process(*args, **kwargs):
            self.assertEqual("150000", os.environ["PHR_EHI_PREVIEW_MAX_ROWS"])
            self.assertEqual("240", os.environ["PHR_EHI_PREVIEW_MAX_SECONDS"])
            kwargs["stdout"].write(json.dumps({"status": "completed", "limits": limits}) + "\n")
            kwargs["stdout"].flush()
            return Finished()
        runner = str(Path(__file__).with_name("run-ehi-qualification.py"))
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ), patch("sys.argv", [runner, "three-cycles-150k", directory]), patch("subprocess.Popen", completed_process), contextlib.redirect_stdout(io.StringIO()) as output:
            runpy.run_path(runner, run_name="__main__")
            self.assertEqual(limits, json.loads(output.getvalue())["limits"])
            limits["rows"] = 50000
            with self.assertRaisesRegex(ValueError, "runtime limits differ"):
                runpy.run_path(runner, run_name="__main__")

    def test_runner_preserves_the_existing_lowered_native_boundary(self):
        class Finished:
            returncode = 0
            def poll(self): return 0
            def wait(self): return 0
        limits = {"rows": 10000, "bytes": 8388608, "native_records": 373,
                  "native_stream_bytes": 33554432, "seconds": 240}
        def completed_process(*args, **kwargs):
            kwargs["stdout"].write(json.dumps({"status": "completed", "limits": limits,
                "lifecycle": {"boundary_native_records": 373}}) + "\n")
            kwargs["stdout"].flush()
            return Finished()
        runner = str(Path(__file__).with_name("run-ehi-qualification.py"))
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ), patch("sys.argv", [runner, "native-exact", directory]), patch("subprocess.Popen", completed_process), contextlib.redirect_stdout(io.StringIO()) as output:
            runpy.run_path(runner, run_name="__main__")
            self.assertEqual(373, json.loads(output.getvalue())["limits"]["native_records"])
            limits["native_records"] = 30000
            with self.assertRaisesRegex(ValueError, "lowered fixture cap"):
                runpy.run_path(runner, run_name="__main__")

    def test_sampler_drains_large_result_without_pipe_deadlock_and_strips_only_empty_scan(self):
        observer_spec = importlib.util.spec_from_file_location("observer", Path(__file__).with_name("observe-phr-resources.py"))
        observer = importlib.util.module_from_spec(observer_spec)
        observer_spec.loader.exec_module(observer)
        class Finished:
            returncode = 0
            def poll(self): return 0
            def wait(self): return 0
        def completed_process(*args, **kwargs):
            kwargs["stdout"].write('Scanned 0 fill(s); updated 0.\n' + __import__("json").dumps({"large_aggregate": "x" * 70000}) + '\n')
            kwargs["stdout"].flush()
            return Finished()
        with tempfile.TemporaryDirectory() as directory, patch.object(observer.subprocess, "Popen", completed_process):
            result = observer.observe(["--qualification=synthetic", "--dir=" + directory], timeout_seconds=300)
            self.assertEqual(70000, len(result["large_aggregate"]))



if __name__ == "__main__": unittest.main()
