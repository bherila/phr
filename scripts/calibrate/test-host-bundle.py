#!/usr/bin/env python3
"""Tiny fixtures for extraction admission; no deployment access."""
import importlib.util
import io
from pathlib import Path
import tarfile
import tempfile
import subprocess
import secrets
import unittest

spec = importlib.util.spec_from_file_location("bundle", Path(__file__).with_name("validate-host-bundle.py"))
bundle = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bundle)


class BundleTest(unittest.TestCase):
    def archive(self, directory, extra=None):
        path = Path(directory) / "bundle.tar.gz"
        with tarfile.open(path, "w:gz") as archive:
            for name in ("artisan", "vendor/autoload.php", "composer.lock", "calibration-source.json"):
                info = tarfile.TarInfo(name)
                info.size = 1
                archive.addfile(info, io.BytesIO(b"x"))
            if extra is not None:
                archive.addfile(extra)
        return path

    def test_minimal_bundle(self):
        with tempfile.TemporaryDirectory() as directory:
            self.assertEqual(4, bundle.validate(self.archive(directory))["members"])

    def test_paths_links_private_configuration_and_duplicate_members_are_refused(self):
        for name in ("/app/escape", "app/../../escape", "app/evil\\name", ".env", "app/.env", "bootstrap/cache/config.php", "artisan"):
            with self.subTest(name=name), tempfile.TemporaryDirectory() as directory:
                with self.assertRaises(ValueError):
                    bundle.validate(self.archive(directory, tarfile.TarInfo(name)))
        for kind in (tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.FIFOTYPE):
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as directory:
                info = tarfile.TarInfo("app/escape")
                info.type = kind
                info.linkname = "/etc/passwd"
                with self.assertRaises(ValueError):
                    bundle.validate(self.archive(directory, info))

    def test_compressed_size_admission(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self.archive(directory)
            old = bundle.MAX_COMPRESSED
            bundle.MAX_COMPRESSED = 1
            try:
                with self.assertRaises(ValueError):
                    bundle.validate(path)
            finally:
                bundle.MAX_COMPRESSED = old

source_spec = importlib.util.spec_from_file_location("source", Path(__file__).with_name("validate-host-source.py"))
source = importlib.util.module_from_spec(source_spec)
source_spec.loader.exec_module(source)
source.SELECTED = ["app"]


class SourceAndCleanupTest(unittest.TestCase):
    def test_source_links_must_remain_in_the_candidate_and_avoid_private_paths(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "app").mkdir()
            (root / "app/safe").write_text("synthetic")
            link = root / "app/link"
            link.symlink_to(root / "app/safe")
            self.assertTrue(source.validate(root))
            for target in (Path("/etc/passwd"), root / ".env", root / "app"):
                if target.name == ".env": target.write_text("synthetic")
                link.unlink()
                link.symlink_to(target)
                with self.subTest(target=target), self.assertRaises(ValueError): source.validate(root)

    def test_cleanup_never_follows_links_or_deletes_an_unowned_root(self):
        script = Path(__file__).with_name("cleanup-host-root.sh")
        root = Path("/tmp/phr-calibration." + secrets.token_hex(5))
        root.mkdir(mode=0o700)
        nonce = "a" * 64
        try:
            marker = root / ".owned-synthetic-calibration"
            marker.write_text("wrong")
            refused = subprocess.run(["bash", str(script), str(root), nonce], capture_output=True)
            self.assertNotEqual(0, refused.returncode)
            self.assertTrue(marker.exists())
            marker.write_text("phr-host-calibration-v1:" + nonce)
            with tempfile.TemporaryDirectory() as outside:
                target = Path(outside) / "preserved"
                target.write_text("synthetic")
                (root / "link").symlink_to(outside, target_is_directory=True)
                done = subprocess.run(["bash", str(script), str(root), nonce], capture_output=True)
                self.assertEqual(0, done.returncode)
                self.assertFalse(root.exists())
                self.assertEqual("synthetic", target.read_text())
        finally:
            if root.exists():
                for child in root.iterdir(): child.unlink()
                root.rmdir()


if __name__ == "__main__":
    unittest.main()
