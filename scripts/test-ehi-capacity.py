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
