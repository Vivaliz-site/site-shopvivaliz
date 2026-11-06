#!/usr/bin/env python3
"""Local, credential-free regression tests for the Marina OCI MySQL backup helper."""
import gzip
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock

SOURCE = Path(__file__).resolve().parents[1] / "ops/mysql-native-oci-backup.py"
spec = importlib.util.spec_from_file_location("sv_mysql_native_backup", SOURCE)
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)


class MysqlOffsiteBackupTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.archive = self.root / "mysql-all-test.sql.gz"
        self.data = b"-- MySQL dump 10.13\nCREATE TABLE test (id int);\n" + bytes(range(256)) * 110
        with gzip.open(self.archive, "wb", compresslevel=1) as stream:
            stream.write(self.data)
        self.assertGreater(self.archive.stat().st_size, backup.MIN_ARCHIVE_BYTES)

    def test_compressed_archive_header_and_crc_pass(self):
        backup.verify_gzip(self.archive)
        self.assertEqual(backup.sha256_file(self.archive), hashlib.sha256(self.archive.read_bytes()).hexdigest())

    def test_corrupt_archive_rejected(self):
        with self.archive.open("ab") as stream:
            stream.write(b"extra")
        # Trailing gzip data may be permitted by Python; corrupt within the compressed stream.
        content = bytearray(self.archive.read_bytes())
        content[110] ^= 0x7F
        self.archive.write_bytes(content)
        with self.assertRaises((OSError, EOFError, gzip.BadGzipFile, UnicodeDecodeError)):
            backup.verify_gzip(self.archive)

    def _fake_oci(self, files):
        def run(argv, timeout=600):
            action = argv[2]
            name = argv[argv.index("--name") + 1]
            local = Path(argv[argv.index("--file") + 1])
            if action == "put":
                files[name] = local.read_bytes()
            elif action == "get":
                local.write_bytes(files[name])
            else:
                raise AssertionError("unexpected OCI action")
        return run

    def test_upload_download_sha256_and_gzip(self):
        objects = {}
        with mock.patch.object(backup, "run_checked", side_effect=self._fake_oci(objects)):
            key = backup.verify_and_copy_offsite(self.archive)
        self.assertTrue(key.startswith("db-backups/mysql-native/"))
        evidence = json.loads(Path(str(self.archive) + ".verified.json").read_text())
        self.assertEqual(evidence["bytes"], self.archive.stat().st_size)
        self.assertEqual(evidence["sha256"], hashlib.sha256(self.archive.read_bytes()).hexdigest())
        self.assertEqual(objects[key], self.archive.read_bytes())

    def test_downloaded_corruption_prevents_success_marker(self):
        objects = {}
        def damaged(argv, timeout=600):
            self._fake_oci(objects)(argv, timeout)
            if argv[2] == "get" and not argv[argv.index("--name") + 1].endswith(".sha256"):
                local = Path(argv[argv.index("--file") + 1])
                local.write_bytes(b"broken" + local.read_bytes()[6:])
        with mock.patch.object(backup, "run_checked", side_effect=damaged):
            with self.assertRaises(RuntimeError):
                backup.verify_and_copy_offsite(self.archive)
        self.assertFalse(Path(str(self.archive) + ".verified.json").exists())


if __name__ == "__main__":
    unittest.main()
