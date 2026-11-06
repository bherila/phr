#!/usr/bin/env python3
"""Small result-contract tests; realistic profiles are CI-only."""
import copy
import importlib.util
from pathlib import Path
import unittest
import tempfile
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("qualify", Path(__file__).with_name("assert-ehi-qualification.py"))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class QualificationTest(unittest.TestCase):
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
