#!/usr/bin/env python3
"""Accept finite aggregate SSH proofs, never echo banners, stderr or unknown stdout."""
import json
from pathlib import Path
import re
import sys

COMMANDS = {"env", "timeout", "realpath", "stat", "id", "sha256sum", "cut", "tar", "find", "mkdir", "cat", "unlink", "rmdir"}
CODES = {"ready", "python_runtime_missing", "python_version_failed", "python_version_invalid",
         "python_version_unsupported", "php_binary_missing", "php_version_failed", "php_version_invalid",
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
            allowed |= {"python_login", "legacy_roots", "legacy_window_matches"}
            for key in ("legacy_roots", "legacy_window_matches"):
                if row.get(key) is not None and (type(row[key]) is not int or not 0 <= row[key] <= 1000):
                    return refused
            if row.get("python_login") not in ("available", "missing"):
                return refused
            allowed |= {"python_version", "php_version"}
            for key in ("python_version", "php_version"):
                value = row.get(key, "")
                if value == "unknown" and row["status"] == "refused":
                    continue
                if not re.fullmatch(r"[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,2}", value):
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
