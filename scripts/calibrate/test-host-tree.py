#!/usr/bin/env python3
"""Tiny manifests exercise PHP core verification; no application/vendor bootstrap."""
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("tree", HERE / "materialize-host-bundle.py")
tree = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tree)
CANDIDATE = "a" * 40


class TreeTest(unittest.TestCase):
    def fixture(self, directory):
        directory = Path(directory)
        archive = directory / "bundle.tar.gz"
        lock = b"synthetic lock"
        content = {"artisan": b"synthetic", "vendor/autoload.php": b"synthetic",
                   "composer.lock": lock, "tests/Support/nested.php": b"synthetic", "scripts/measure.php": b"synthetic",
                   "calibration-source.json": json.dumps({
                       "candidate": CANDIDATE, "lock_sha256": hashlib.sha256(lock).hexdigest()}).encode()}
        with tarfile.open(archive, "w:gz") as target:
            for name in ("vendor", "app", "app/empty"):
                info = tarfile.TarInfo(name)
                info.type = tarfile.DIRTYPE
                target.addfile(info)
            for name, value in content.items():
                info = tarfile.TarInfo(name)
                info.size = len(value)
                target.addfile(info, io.BytesIO(value))
        output = directory / "payload"
        digest = tree.materialize(archive, output, CANDIDATE)
        return output, digest

    def verify(self, root, digest, candidate=CANDIDATE):
        return subprocess.run(["php", "-n", "-d", f"open_basedir={root}", str(root / "verify-host-tree.php"),
                               str(root), digest, candidate], capture_output=True, text=True)

    def test_valid_tree_preserves_empty_directories_and_deterministic_manifest(self):
        with tempfile.TemporaryDirectory() as first, tempfile.TemporaryDirectory() as second:
            root, digest = self.fixture(first)
            other, other_digest = self.fixture(second)
            self.assertEqual(digest, other_digest)
            self.assertEqual((root / "tree-manifest.json").read_bytes(), (other / "tree-manifest.json").read_bytes())
            self.assertEqual(0, self.verify(root, digest).returncode)
            self.assertTrue((root / "application/app/empty").is_dir())
            manifest = json.loads((root / "tree-manifest.json").read_text())
            self.assertGreater(manifest["tree_members"], manifest["bundle_size"]["members"])
            self.assertTrue((root / "application/tests/Support").is_dir())

    def test_tampering_missing_files_extras_file_and_directory_links_are_refused(self):
        mutations = [lambda app: (app / "artisan").write_text("tampered"),
                     lambda app: (app / "artisan").unlink(),
                     lambda app: (app / "unknown").write_text("extra"),
                     lambda app: (app / "app/extra-empty").mkdir(),
                     lambda app: (app / "app/link").symlink_to("../artisan"),
                     lambda app: (app / "app/link").symlink_to("../vendor", target_is_directory=True)]
        for mutation in mutations:
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as directory:
                root, digest = self.fixture(directory)
                mutation(root / "application")
                self.assertNotEqual(0, self.verify(root, digest).returncode)

    def test_wrong_manifest_digest_candidate_lock_and_traversal_are_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            root, digest = self.fixture(directory)
            self.assertNotEqual(0, self.verify(root, "0" * 64).returncode)
            self.assertNotEqual(0, self.verify(root, digest, "b" * 40).returncode)
            manifest_path = root / "tree-manifest.json"
            original = json.loads(manifest_path.read_text())
            for change in (lambda data: data["files"].update({"../escape": data["files"]["artisan"]}),
                           lambda data: data.update({"lock_sha256": "0" * 64})):
                data = json.loads(json.dumps(original))
                change(data)
                raw = json.dumps(data).encode()
                manifest_path.write_bytes(raw)
                self.assertNotEqual(0, self.verify(root, hashlib.sha256(raw).hexdigest()).returncode)


if __name__ == "__main__":
    unittest.main()
