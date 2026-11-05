#!/usr/bin/env python3
"""Validate a synthetic EHI resource observation without hiding budget refusals."""

import json
from pathlib import Path
import sys


def validate(result):
    if result["operation"] != "preview" or result["shape"] != "labs":
        raise ValueError("Expected a lab preview observation")
    if result["clinical_lab_rows"] != result["history_records"]:
        raise ValueError("Preview modified clinical lab records")
    if result["status"] == "completed":
        if any(result["work_after"][dimension] > result["limits"][dimension] for dimension in ["rows", "bytes"]):
            raise ValueError("A completed preview exceeded its admitted work budget")
        if not result["preview_complete"] or not result["generation_complete"]:
            raise ValueError("A successful result did not publish a complete generation")
        proposed = result["preview_counts"].get("lab_result", {}).get("create", 0)
        if proposed != result["requested_records"]:
            raise ValueError("A successful preview omitted synthetic lab proposals")
    elif result["status"] == "resource_limit":
        if result["preview_complete"] or result["generation_complete"]:
            raise ValueError("A refused preview published a partial generation")
        if result["requested_records"] == 1000 and result["history_records"] == 0:
            raise ValueError("The small baseline package no longer fits")
    else:
        raise ValueError("Unexpected preview failure")
    return (
        f"- {result['database_driver']} {result['database_version']}: "
        f"{result['requested_records']} incoming labs + {result['history_records']} prior labs; "
        f"limits {result['limits']['rows']} rows / {result['limits']['bytes']} bytes / "
        f"{result['limits']['seconds']}s: **{result['status']}**, "
        f"work {result['work_before']['rows']} → {result['work_after']['rows']} rows / "
        f"{result['work_before']['bytes']} → {result['work_after']['bytes']} bytes, "
        f"{result['seconds']}s, peak PHP {result['peak_php_bytes']} bytes, "
        f"sampled RSS {result['sampled_rss_kib']} KiB, {result['queries']} queries."
    )


if __name__ == "__main__":
    print(validate(json.loads(Path(sys.argv[1]).read_text())))
