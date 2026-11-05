#!/usr/bin/env python3
"""Accept only the two expected aggregate observations and confirmed private cleanup."""
import importlib.util
import json
from pathlib import Path
import sys

spec = importlib.util.spec_from_file_location("capacity", Path(__file__).parents[1] / "assert-ehi-capacity.py")
capacity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(capacity)


def validate(path, candidate, manifest_path=None):
    raw = Path(path).read_bytes()
    if len(raw) > 192 * 1024:
        raise ValueError("Host output exceeded admission")
    rows = [json.loads(line) for line in raw.splitlines()]
    if len(rows) != 3 or rows[-1] != {"cleanup": "confirmed", "scope": "owned-synthetic-root"}:
        raise ValueError("Host calibration did not confirm cleanup")
    if manifest_path is not None:
        manifest = json.loads(Path(manifest_path).read_text())
        if manifest["candidate"] != candidate:
            raise ValueError("Candidate manifest mismatch")
        for row in rows[:2]:
            row["measurement_kind"] = "deployment-host-isolated-sqlite"
            row["candidate"] = candidate
            row["bundle_size"] = manifest["bundle_size"]
            row["sampled_rss_kib"] = None
    for row, operation in zip(rows[:2], ("preview", "lifecycle")):
        if (row["candidate"] != candidate or row["measurement_kind"] != "deployment-host-isolated-sqlite"
                or row["database_driver"] != "sqlite" or row["operation"] != operation
                or row["requested_records"] != 1000 or row["history_records"] != 2000):
            raise ValueError("Host observation does not match the selected candidate/profile")
        if row["limits"] != {"rows": 10000, "bytes": 8388608, "seconds": 240}:
            raise ValueError("Host preview defaults changed")
        if row["native_limits"] != {"records": 20000, "record_stream_bytes": 33554432}:
            raise ValueError("Host native defaults changed")
        print(capacity.validate(row))
        print(f"  Actual-host PHP {row['php_version']}; ru_maxrss {row['max_rss_kib']} KiB; synthetic SQLite/local disks only.")
    return rows


if __name__ == "__main__":
    validate(sys.argv[1], sys.argv[2])
