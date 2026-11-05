#!/usr/bin/env python3
"""Validate the entire synthetic code bundle before any remote extraction."""
import json
from pathlib import Path, PurePosixPath
import sys
import tarfile

MAX_COMPRESSED = 80 * 1024 * 1024
MAX_EXPANDED = 500 * 1024 * 1024
ROOTS = {"app", "bootstrap", "config", "database", "routes", "tests", "scripts", "vendor",
         "composer.json", "composer.lock", "artisan", "calibration-source.json"}


def validate(path):
    if not 0 < Path(path).stat().st_size <= MAX_COMPRESSED:
        raise ValueError("Compressed synthetic bundle exceeds admission")
    size = 0
    count = 0
    names = set()
    with tarfile.open(path, "r|gz") as archive:
        for member in archive:
            name = member.name.rstrip("/")
            parts = PurePosixPath(name).parts
            if (not name or name.startswith("/") or ".." in parts or "\\" in name
                    or not parts or parts[0] not in ROOTS or name in names
                    or not (member.isdir() or member.isfile())):
                raise ValueError("Unsafe synthetic bundle member")
            if any(part in (".env", ".git") for part in parts):
                raise ValueError("Environment/private metadata in synthetic bundle")
            if name.startswith("bootstrap/cache/"):
                raise ValueError("Compiled application cache in synthetic bundle")
            names.add(name)
            size += member.size
            count += 1
            if size > MAX_EXPANDED or member.size > 128 * 1024 * 1024 or count > 50000:
                raise ValueError("Expanded synthetic bundle exceeds admission")
    if not {"artisan", "vendor/autoload.php", "composer.lock", "calibration-source.json"}.issubset(names):
        raise ValueError("Incomplete synthetic bundle")
    return {"compressed_bytes": Path(path).stat().st_size, "expanded_bytes": size, "members": count}


if __name__ == "__main__":
    print(json.dumps(validate(sys.argv[1])))
