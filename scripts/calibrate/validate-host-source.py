#!/usr/bin/env python3
"""Prove materialized source links cannot pull deployment secrets/outside files into tar."""
import os
from pathlib import Path
import stat
import sys

SELECTED = ["app", "bootstrap", "config", "database", "routes", "tests/Support",
            "scripts/measure-phr-resources.php", "composer.json", "composer.lock", "artisan",
            "vendor", "calibration-source.json"]


def validate(root):
    root = Path(root).resolve(strict=True)
    for selected in SELECTED:
        path = root / selected
        paths = [path]
        if path.is_dir():
            paths += [Path(directory) / name for directory, dirs, files in os.walk(path, followlinks=False)
                      for name in dirs + files]
        for entry in paths:
            relative = entry.relative_to(root)
            if relative.parts[:2] == ("bootstrap", "cache"):
                continue  # Excluded by tar; never package compiled deployment configuration.
            if entry.is_symlink():
                target = entry.resolve(strict=True)
                if not target.is_relative_to(root):
                    raise ValueError("Synthetic source link escapes the candidate checkout")
                if target.is_dir():
                    raise ValueError("Synthetic bundles do not materialize directory links")
                target_parts = target.relative_to(root).parts
                if (any(part in (".env", ".git", "storage") for part in target_parts)
                        or target_parts[:2] == ("bootstrap", "cache")):
                    raise ValueError("Synthetic source link names private configuration/storage")
            elif not (stat.S_ISREG(entry.stat().st_mode) or stat.S_ISDIR(entry.stat().st_mode)):
                raise ValueError("Synthetic source includes a special file")
    return True


if __name__ == "__main__":
    validate(sys.argv[1])
