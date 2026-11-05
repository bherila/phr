#!/usr/bin/env python3
"""Validate a synthetic EHI resource observation without hiding budget refusals."""

import json
from pathlib import Path
import sys


def validate_lifecycle(result):
    lifecycle = result["lifecycle"]
    status = result["status"]
    if lifecycle["status"] != status:
        raise ValueError("Lifecycle status mismatch")
    if status == "completed":
        if not lifecycle["round_trip_verified"] or lifecycle["verified_tables"] < 40 or lifecycle["verified_artifacts"] < 1:
            raise ValueError("Lifecycle completion omitted round-trip evidence")
        state = lifecycle["review_state"]
        if state["phr_lab_results"] != result["history_records"] + 1 or state["phr_ehi_proposal_decisions"] != 2:
            raise ValueError("Clinical/review lifecycle counts changed")
        if state["proposal_statuses"].get("accepted") != 1 or state["proposal_statuses"].get("rejected") != 1:
            raise ValueError("Accepted/rejected review history changed")
        if state["phr_patient_user_access"] != 1:
            raise ValueError("Restore changed owner access")
        if lifecycle["archive_records"] > result["native_limits"]["records"] or lifecycle["record_stream_bytes"] > result["native_limits"]["record_stream_bytes"]:
            raise ValueError("Completed archive exceeded native admission")
    elif status in ("record_count_limit", "record_stream_size_limit"):
        if lifecycle["round_trip_verified"] or lifecycle["refused_stage"] != "backup":
            raise ValueError("Refusal incorrectly claims a completed lifecycle")
        if result["requested_records"] <= 1000:
            raise ValueError("Baseline lifecycle no longer fits")
    elif status == "preview_resource_limit":
        if lifecycle["round_trip_verified"] or lifecycle["stages"]:
            raise ValueError("Refused preview proceeded to review or restore")
    else:
        raise ValueError("Unexpected lifecycle failure")
    return (f"- {result['database_driver']} lifecycle {result['requested_records']} incoming / "
            f"{result['history_records']} prior labs, native limits {result['native_limits']}: **{status}**, "
            f"round trip verified={lifecycle['round_trip_verified']}, {result['seconds']}s, "
            f"peak PHP {result['peak_php_bytes']} bytes, sampled RSS {result['sampled_rss_kib']} KiB. "
            f"Stages: {lifecycle['stages']}")


def validate(result):
    if result["operation"] == "lifecycle" and result["shape"] == "labs":
        return validate_lifecycle(result)
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
