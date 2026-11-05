#!/usr/bin/env python3
"""Tiny fixtures for extraction admission; no deployment access."""
import importlib.util
import io
from pathlib import Path
import tarfile
import tempfile
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


if __name__ == "__main__":
    unittest.main()
