#!/usr/bin/env python3
"""Accept finite aggregate SSH proofs, never echo banners, stderr or unknown stdout."""
import json
from pathlib import Path
import re
import sys

COMMANDS = {"env", "timeout", "realpath", "stat", "id", "sha256sum", "cut", "rsync", "find", "mkdir", "cat", "unlink", "rmdir"}
CODES = {"ready", "php_binary_missing", "php_version_failed", "php_version_invalid",
         "null_device_invalid", "legacy_scan_failed", "legacy_scan_invalid", "mkdir_failed"} | {"command_missing_" + command for command in COMMANDS}


def validate(path, stage, exit_code):
    refused = {"stage": stage, "status": "refused", "code": "invalid_output"}
    try:
        raw = Path(path).read_bytes()
        if not raw and exit_code:
            return refused | {"code": "transport_failed"}
        if len(raw) > 2048:
            return refused
        row = json.loads(raw)
        if (not isinstance(row, dict) or row.get("stage") != stage or row.get("code") not in CODES
                or row.get("status") not in ("ready", "refused")):
            return refused
        if row["code"] == "ready" and (row["status"] != "ready" or exit_code):
            return refused | {"code": "transport_failed"}
        if row["code"] != "ready" and row["status"] != "refused":
            return refused
        allowed = {"stage", "status", "code"}
        if stage == "prerequisites":
            allowed |= {"legacy_roots", "legacy_window_matches"}
            for key in ("legacy_roots", "legacy_window_matches"):
                if row.get(key) is not None and (type(row[key]) is not int or not 0 <= row[key] <= 1000):
                    return refused
            allowed.add("php_version")
            value = row.get("php_version", "")
            if not (value == "unknown" and row["status"] == "refused") and not re.fullmatch(r"8\.5\.[0-9]{1,2}", value):
                return refused
        if set(row) != allowed:
            return refused
        return row
    except (ValueError, OSError, TypeError):
        return refused


if __name__ == "__main__":
    result = validate(sys.argv[1], sys.argv[2], int(sys.argv[3]))
    print(json.dumps(result))
    sys.exit(0 if result["status"] == "ready" else 1)
