#!/usr/bin/env python3
"""The capacity lane must distinguish complete previews from safe refusals."""

import copy
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("capacity", Path(__file__).with_name("assert-ehi-capacity.py"))
capacity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(capacity)


class CapacityObservationTest(unittest.TestCase):
    def result(self):
        return {
            "operation": "preview", "shape": "labs", "status": "completed",
            "requested_records": 1000, "history_records": 0, "clinical_lab_rows": 0,
            "preview_complete": True, "generation_complete": True,
            "preview_counts": {"lab_result": {"create": 1000}},
            "database_driver": "sqlite", "database_version": "synthetic",
            "limits": {"rows": 10000, "bytes": 8388608, "seconds": 240},
            "work_before": {"rows": 1000, "bytes": 1000000}, "work_after": {"rows": 6300, "bytes": 5000000},
            "seconds": 1.0, "peak_php_bytes": 1000000, "sampled_rss_kib": 1024, "queries": 10,
        }

    def lifecycle(self):
        return self.result() | {"operation": "lifecycle", "native_limits": {"records": 20000, "record_stream_bytes": 33554432},
            "lifecycle": {"status": "completed", "round_trip_verified": True, "verified_tables": 42,
                "verified_artifacts": 1, "archive_records": 9000, "record_stream_bytes": 10000000, "stages": {},
                "review_state": {"phr_lab_results": 1, "phr_ehi_proposal_decisions": 2,
                    "phr_patient_user_access": 1, "proposal_statuses": {"accepted": 1, "rejected": 1}}}}

    def test_lifecycle_requires_round_trip_clinical_review_and_budget_evidence(self):
        result = self.lifecycle()
        self.assertIn("**completed**", capacity.validate(result))
        for change in [{"round_trip_verified": False}, {"verified_tables": 0}, {"verified_artifacts": 0},
                       {"archive_records": 20001}, {"record_stream_bytes": 33554433},
                       {"review_state": result["lifecycle"]["review_state"] | {"phr_lab_results": 0}}]:
            with self.subTest(change=change), self.assertRaises(ValueError):
                capacity.validate(result | {"lifecycle": result["lifecycle"] | change})

    def test_native_and_preview_refusals_never_claim_lifecycle_success(self):
        for status, stages in [("record_count_limit", {"backup": {}}),
                               ("record_stream_size_limit", {"backup": {}}), ("preview_resource_limit", {})]:
            result = self.lifecycle() | {"requested_records": 5000, "status": status}
            result["lifecycle"] |= {"status": status, "round_trip_verified": False, "stages": stages, "refused_stage": "backup"}
            self.assertIn(status, capacity.validate(result))
            result["lifecycle"]["round_trip_verified"] = True
            with self.assertRaises(ValueError):
                capacity.validate(result)

    def test_a_complete_result_reports_the_measured_profile(self):
        self.assertIn("**completed**", capacity.validate(self.result()))

    def test_larger_refusals_remain_visible_and_never_count_as_capacity_success(self):
        result = self.result() | {
            "requested_records": 2000, "status": "resource_limit", "preview_complete": False,
            "generation_complete": False, "preview_counts": None,
        }
        self.assertIn("**resource_limit**", capacity.validate(result))

    def test_missing_labs_clinical_writes_partial_generations_and_small_refusals_fail(self):
        changes = [
            {"preview_counts": {"lab_result": {"create": 999}}},
            {"clinical_lab_rows": 1},
            {"work_after": {"rows": 10001, "bytes": 5000000}},
            {"work_after": {"rows": 6300, "bytes": 8388609}},
            {"generation_complete": False},
            {"preview_complete": False},
            {"status": "resource_limit"},
            {"status": "unexpected_failure"},
            {"operation": "backup"},
        ]
        for change in changes:
            with self.subTest(change=change), self.assertRaises(ValueError):
                capacity.validate(copy.deepcopy(self.result()) | change)

    def test_a_refusal_with_a_published_generation_fails(self):
        result = self.result() | {"requested_records": 2000, "status": "resource_limit", "preview_complete": False}
        with self.assertRaises(ValueError):
            capacity.validate(result)


if __name__ == "__main__":
    unittest.main()
