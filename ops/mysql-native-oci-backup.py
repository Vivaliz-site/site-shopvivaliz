#!/usr/bin/env python3
"""Verified native MySQL dump and OCI backup for Marina. Never logs credentials."""
import argparse
import fcntl
import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

OCI = Path("/home/ubuntu/.venvs/oci-cli/bin/oci")
NAMESPACE = "grsk1ummvveq"
BUCKET = "shopvivaliz-marina-archive"
BACKUPS = Path("/var/backups/shopvivaliz-mysql-native")
LOCK = Path("/run/lock/shopvivaliz-mysql-native-oci-backup.lock")
MIN_FREE_BYTES = 10 * 1024**3
MIN_ARCHIVE_BYTES = 10000

def sha256_file(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()

def verify_gzip(path):
    if not path.is_file() or path.stat().st_size < MIN_ARCHIVE_BYTES:
        raise RuntimeError("archive_missing_or_too_small")
    with gzip.open(path, "rb") as stream:
        head = stream.read(2048)
        if b"MySQL dump" not in head and b"MariaDB dump" not in head:
            raise RuntimeError("unrecognized_mysql_dump_header")
        while stream.read(1024 * 1024):
            pass

def run_checked(argv, timeout=600):
    subprocess.run(argv, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                   check=True, timeout=timeout)

def create_snapshot():
    if shutil.disk_usage(BACKUPS).free < MIN_FREE_BYTES:
        raise RuntimeError("insufficient_disk_headroom")
    name = "mysql-all-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + ".sql.gz"
    result = BACKUPS / name
    partial = BACKUPS / (name + ".partial")
    command = [
        "/usr/bin/mysqldump", "--all-databases", "--single-transaction",
        "--quick", "--routines", "--events", "--triggers", "--hex-blob",
        "--no-tablespaces", "--default-character-set=utf8mb4",
    ]
    try:
        with partial.open("xb") as output:
            proc = subprocess.Popen(command, stdout=subprocess.PIPE,
                                    stderr=subprocess.DEVNULL)
            try:
                with gzip.GzipFile(fileobj=output, mode="wb", compresslevel=1) as zipper:
                    shutil.copyfileobj(proc.stdout, zipper, length=1024 * 1024)
            finally:
                proc.stdout.close()
            code = proc.wait(timeout=900)
            output.flush()
            os.fsync(output.fileno())
        if code != 0:
            raise RuntimeError("mysqldump_nonzero_exit")
        verify_gzip(partial)
        partial.rename(result)
        result.chmod(0o600)
        return result
    finally:
        if partial.exists():
            partial.unlink()

def verify_and_copy_offsite(archive):
    verify_gzip(archive)
    digest = sha256_file(archive)
    manifest = Path(str(archive) + ".sha256")
    manifest.write_text(digest + "  " + archive.name + "\n", encoding="utf-8")
    manifest.chmod(0o600)
    object_name = "db-backups/mysql-native/" + archive.name
    common = ["--auth", "instance_principal",
              "--namespace-name", NAMESPACE, "--bucket-name", BUCKET]
    run_checked([str(OCI), "os", "object", "put", *common, "--file", str(archive),
                 "--name", object_name, "--force"])
    run_checked([str(OCI), "os", "object", "put", *common, "--file", str(manifest),
                 "--name", object_name + ".sha256", "--force"])
    with tempfile.TemporaryDirectory(prefix="sv-mysql-oci-verify-", dir="/var/tmp") as temp:
        restored = Path(temp) / "backup.sql.gz"
        remote_manifest = Path(temp) / "backup.sha256"
        run_checked([str(OCI), "os", "object", "get", *common,
                     "--name", object_name, "--file", str(restored)])
        run_checked([str(OCI), "os", "object", "get", *common,
                     "--name", object_name + ".sha256", "--file", str(remote_manifest)])
        remote_digest = remote_manifest.read_text(encoding="utf-8").split()[0]
        if restored.stat().st_size != archive.stat().st_size:
            raise RuntimeError("remote_size_mismatch")
        if remote_digest != digest or sha256_file(restored) != digest:
            raise RuntimeError("remote_checksum_mismatch")
        verify_gzip(restored)
    verified = Path(str(archive) + ".verified.json")
    verified.write_text(json.dumps({
        "verified_at": datetime.now(timezone.utc).isoformat(),
        "object_name": object_name, "bytes": archive.stat().st_size,
        "sha256": digest, "checks": ["local_gzip", "downloaded_gzip",
                                    "remote_sha256", "remote_byte_count"],
    }, sort_keys=True) + "\n", encoding="utf-8")
    verified.chmod(0o600)
    return object_name

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="Read-only preflight")
    parser.add_argument("--verify-existing", type=Path, help="Re-upload and verify root-owned snapshot")
    opts = parser.parse_args()
    os.umask(0o077)
    if os.geteuid() != 0:
        raise RuntimeError("root_required")
    if not OCI.is_file() or not os.access(OCI, os.X_OK):
        raise RuntimeError("oci_instance_principal_cli_missing")
    if not os.path.isfile("/usr/bin/mysqldump"):
        raise RuntimeError("mysqldump_missing")
    if opts.check:
        print("MYSQL_NATIVE_BACKUP_PREFLIGHT=PASS")
        return
    BACKUPS.mkdir(mode=0o700, parents=True, exist_ok=True)
    BACKUPS.chmod(0o700)
    lock_fd = os.open(str(LOCK), os.O_RDWR | os.O_CREAT, 0o600)
    try:
        fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if opts.verify_existing:
            archive = opts.verify_existing.resolve(strict=True)
            root = Path("/var/backups").resolve()
            if root not in archive.parents:
                raise RuntimeError("archive_outside_protected_backup_root")
        else:
            archive = create_snapshot()
        key = verify_and_copy_offsite(archive)
        print("MYSQL_NATIVE_BACKUP_OFFSITE=PASS bytes=" +
              str(archive.stat().st_size) + " object=" + key +
              " remote_gzip_sha256=PASS")
    finally:
        os.close(lock_fd)

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("MYSQL_NATIVE_BACKUP_OFFSITE=FAIL cause=" + type(exc).__name__ +
              " phase=" + str(exc)[:75], file=sys.stderr)
        sys.exit(1)
