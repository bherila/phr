#!/usr/bin/env python3
"""Experimental refusal is an unmet qualification, never a capacity success."""
import json
from pathlib import Path
import sys

RESOURCE_REFUSALS = {"resource_limit", "record_count_limit", "record_stream_size_limit", "artifact_metadata_limit", "timeout"}


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
    return (f"- {result['profile']} ({expectation}): **{status}**, qualification_met={met}, "
            f"stage={lifecycle.get('refused_stage', result.get('refused_stage', 'complete'))}, "
            f"wall={result['profile_wall_seconds']}s / ceiling={result['operation_ceiling_seconds']}s, "
            f"RSS={result['sampled_rss_kib']}KiB; no production capacity claim.")


if __name__ == "__main__":
    path = Path(sys.argv[1])
    result = json.loads(path.read_text())
    print(validate(result))
    path.write_text(json.dumps(result) + "\n")
