#!/usr/bin/env python3
"""Publish only validated aggregate results or a finite runtime stage refusal."""
import importlib.util
import json
from pathlib import Path
import sys

spec = importlib.util.spec_from_file_location("results", Path(__file__).with_name("assert-host-results.py"))
results = importlib.util.module_from_spec(spec)
spec.loader.exec_module(results)
PHASES = {"ownership", "tree_admission", "prepare", "preview", "lifecycle", "publish"}


def publish(raw_path, candidate, exit_code, output_path, summary_path, manifest_path=None):
    try:
        raw = Path(raw_path).read_bytes()
        if len(raw) > 192 * 1024:
            raise ValueError("Output exceeds admission")
        rows = [json.loads(line) for line in raw.splitlines()]
        if exit_code:
            for row in rows:
                if (isinstance(row, dict) and set(row) == {"stage", "status", "code"}
                        and row["stage"] in PHASES and row["status"] == "refused" and row["code"] == "phase_failed"):
                    print(json.dumps(row))
                    return False
            raise ValueError("No validated runtime refusal")
        # This validator emits only safe aggregate summaries; never raw input.
        import contextlib
        with Path(summary_path).open("a") as summary, contextlib.redirect_stdout(summary):
            rows = results.validate(raw_path, candidate, manifest_path)
        Path(output_path).write_text("\n".join(json.dumps(row) for row in rows) + "\n")
        return True
    except (ValueError, OSError, TypeError, KeyError):
        print(json.dumps({"stage": "runtime", "status": "refused", "code": "invalid_output"}))
        return False


if __name__ == "__main__":
    sys.exit(0 if publish(sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4], sys.argv[5], sys.argv[6] if len(sys.argv) > 6 else None) else 1)
