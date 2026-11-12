#!/usr/bin/env python3
"""Experimental refusal is an unmet qualification, never a capacity success."""
import json
from pathlib import Path
import sys

RESOURCE_REFUSALS = {"resource_limit", "record_count_limit", "record_stream_size_limit", "artifact_metadata_limit", "timeout"}


def capacity_observation(result):
    """Persisted margin is an upper bound; pending reservations are not measured."""
    limits = result.get("limits")
    if not limits:
        return None
    snapshots = {name: stage["durable"] for name, stage in result.get("stages", {}).items() if "durable" in stage}
    work = [snapshot["persisted_preview_work"] for snapshot in snapshots.values() if "persisted_preview_work" in snapshot]
    lifecycle = result.get("lifecycle", {})
    native = None
    if "archive_records" in lifecycle and "record_stream_bytes" in lifecycle:
        native = {"archive_records": lifecycle["archive_records"], "record_stream_bytes": lifecycle["record_stream_bytes"],
                  "record_margin": limits["native_records"] - lifecycle["archive_records"],
                  "stream_byte_margin": limits["native_stream_bytes"] - lifecycle["record_stream_bytes"],
                  "round_trip_verified": lifecycle.get("round_trip_verified", False)}
    peak_rows = max((entry["rows"] for entry in work), default=None)
    peak_bytes = max((entry["bytes"] for entry in work), default=None)
    return {"persisted_preview_work": {"observed_peak_rows": peak_rows, "observed_peak_bytes": peak_bytes,
                "row_margin_upper_bound": None if peak_rows is None else limits["rows"] - peak_rows,
                "byte_margin_upper_bound": None if peak_bytes is None else limits["bytes"] - peak_bytes,
                "includes_in_flight_reservations": False, "metrics_incomplete": result["status"] == "timeout"},
            "durable_growth": {name: {key: value for key, value in snapshot.items() if key != "tables"}
                               for name, snapshot in snapshots.items()},
            "native": native, "whole_profile_time_margin_seconds": round(result["operation_ceiling_seconds"] - result["profile_wall_seconds"], 6)}


def validate(result):
    declaration = result["declaration"]
    status = result["status"]
    lifecycle = result.get("lifecycle", {})
    if status == "completed":
        if not all(lifecycle.get(key) is True for key in ("round_trip_verified", "cleanup_verified", "independent_download_verified", "access_semantics_verified")):
            raise ValueError("Completed qualification omitted recovery/deletion/access proof")
        if declaration["sharing"] and not all(lifecycle.get(key) is True for key in ("existing_opt_in_shares_preserved", "new_patient_opt_in_blocked")):
            raise ValueError("Sharing policy was not exercised")
        if declaration["review"] == "full" and lifecycle["review_state"]["proposal_statuses"].get("pending", 0):
            raise ValueError("Full review left proposals undecided")
        if declaration.get("exercise"):
            replay = result["replay"]
            if not replay["identical"]["archive_bytes_identical"] or replay["repacked"]["archive_bytes_identical"]:
                raise ValueError("Archive replay controls were not distinct")
            if not replay["changed_manifest"]["new_package"] or not replay["changed_manifest"]["clinical_rows_unchanged"]:
                raise ValueError("Manifest-only replay duplicated clinical data")
            if replay.get("cycles_completed", 0) != declaration.get("cycles", 0):
                raise ValueError("Declared changed cycles did not complete")
        for table, expected in declaration.get("expected_clinical_counts", {}).items():
            if lifecycle["review_state"][table] != expected:
                raise ValueError("Declared mixed mapping clinical output differs")
        if declaration.get("artifact_boundary") == "exact" and lifecycle.get("verified_artifacts") != declaration["artifact_limit"]:
            raise ValueError("Exact artifact-count boundary did not complete at its declared cap")
        if lifecycle["verified_tables"] < 40 or lifecycle["verified_artifacts"] < 1:
            raise ValueError("Portable content/source evidence missing")
    elif status not in RESOURCE_REFUSALS:
        raise ValueError("Unexpected qualification failure")
    elif status == "resource_limit" and lifecycle.get("generation_complete") is not False:
        raise ValueError("Preview refusal did not prove incomplete generation")
    expectation = declaration["expectation"]
    if expectation == "must_complete" and status != "completed":
        raise ValueError("Required qualification did not complete")
    if expectation == "must_refuse_at_stage":
        if status != declaration["refusal"] or lifecycle.get("refused_stage", result.get("refused_stage")) != declaration["refused_stage"]:
            raise ValueError("Expected boundary refusal was not observed at its declared stage")
        if declaration.get("artifact_boundary") == "over" and lifecycle.get("candidate_artifacts") != declaration["artifact_limit"] + 1:
            raise ValueError("One-over artifact count was not attained")
        if lifecycle.get("round_trip_verified") or lifecycle.get("cleanup_verified"):
            raise ValueError("Expected refusal claims completed recovery")
    met = status == "completed"
    result["qualification_met"] = met
    result["capacity_observation"] = capacity_observation(result)
    return (f"- {result['profile']} ({expectation}): **{status}**, qualification_met={met}, "
            f"stage={lifecycle.get('refused_stage', result.get('refused_stage', 'complete'))}, "
            f"wall={result['profile_wall_seconds']}s / ceiling={result['operation_ceiling_seconds']}s, "
            f"RSS={result['sampled_rss_kib']}KiB; no production capacity claim.")


if __name__ == "__main__":
    path = Path(sys.argv[1])
    result = json.loads(path.read_text())
    print(validate(result))
    path.write_text(json.dumps(result) + "\n")
