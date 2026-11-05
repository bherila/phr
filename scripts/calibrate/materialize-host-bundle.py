#!/usr/bin/env python3
"""CI-only normalized extraction and a deterministic digest-pinned regular-file inventory."""
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import sys
import tarfile

spec = importlib.util.spec_from_file_location("bundle", Path(__file__).with_name("validate-host-bundle.py"))
bundle = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bundle)


def materialize(archive_path, output, candidate):
    size = bundle.validate(archive_path)
    output = Path(output)
    output.mkdir(mode=0o700)  # Existing trees are never reused.
    app = output / "application"
    app.mkdir(mode=0o700)
    files = {}
    directories = set()
    with tarfile.open(archive_path, "r|gz") as archive:
        for member in archive:
            name = member.name.rstrip("/")
            destination = app / name
            parents = list(Path(name).parents)[:-1]
            for parent in reversed(parents):
                directories.add(parent.as_posix())
                (app / parent).mkdir(mode=0o700, exist_ok=True)
            if member.isdir():
                directories.add(name)
                destination.mkdir(mode=0o700, exist_ok=True)
            else:
                digest = hashlib.sha256()
                with archive.extractfile(member) as source, destination.open("xb") as target:
                    while block := source.read(1024 * 1024):
                        digest.update(block)
                        target.write(block)
                destination.chmod(0o600)
                if destination.stat().st_size != member.size:
                    raise ValueError("Incomplete normalized source")
                files[name] = {"bytes": member.size, "sha256": digest.hexdigest()}
    source = json.loads((app / "calibration-source.json").read_text())
    lock = hashlib.sha256((app / "composer.lock").read_bytes()).hexdigest()
    if source != {"candidate": candidate, "lock_sha256": lock}:
        raise ValueError("Source/candidate/lock identity mismatch")
    manifest = {"format": "synthetic-phr-tree-v1", "candidate": candidate, "lock_sha256": lock,
                "bundle_size": size, "directories": sorted(directories), "files": dict(sorted(files.items()))}
    raw = json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode()
    if len(raw) > 16 * 1024 * 1024 or len(files) + len(directories) > 50000:
        raise ValueError("Tree inventory exceeds admission")
    (output / "tree-manifest.json").write_bytes(raw)
    for name in ("verify-host-tree.php", "ehi-host-remote.sh"):
        shutil.copyfile(Path(__file__).with_name(name), output / name)
    return hashlib.sha256(raw).hexdigest()


if __name__ == "__main__":
    print(materialize(*sys.argv[1:]))
