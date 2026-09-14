#!/usr/bin/env python3
import argparse
import hashlib
import json
import os
import subprocess
import tempfile
from pathlib import Path

NAMESPACE = "gruilpqsbqqb"
BUCKET = "shopvivaliz-free-archive"
OCI = "/home/ubuntu/.venvs/oci-cli/bin/oci"
PG_DUMP = "/usr/bin/pg_dump"
PG_RESTORE = "/usr/bin/pg_restore"

PROFILES = {
    "main": [
        {"name": "solange-production", "container": "supabase_db_solange-rolla-consultorio"},
        {"name": "solange-staging", "container": "supabase_db_solange-client-demo"},
        {"name": "mei-email", "container": "mei-mg-email-db"},
    ],
    "secondary": [
        {"name": "mlrr-phase3", "container": "mlrr-pg-prod", "user": "postgres", "database": "mlrr_phase3", "mode": "container"},
    ],
}


def targets_for(profile):
    return [dict(item) for item in PROFILES[profile]]


def object_name(profile, name):
    return f"db-backups/{profile}/{name}-latest.dump"


def inspect_container(name):
    raw = subprocess.check_output(["docker", "inspect", name], text=True)
    return json.loads(raw)[0]


def connection_for(target):
    info = inspect_container(target["container"])
    env = {}
    for item in info["Config"].get("Env", []):
        key, _, value = item.partition("=")
        env[key] = value
    user = target.get("user") or env.get("POSTGRES_USER")
    database = target.get("database") or env.get("POSTGRES_DB")
    password = env.get("POSTGRES_PASSWORD", "")
    ports = info["NetworkSettings"]["Ports"].get("5432/tcp") or []
    if not ports:
        raise RuntimeError(f"no published postgres port for {target['container']}")
    return {
        "host": "127.0.0.1",
        "port": ports[0]["HostPort"],
        "user": user,
        "database": database,
        "password": password,
    }


def run_checked(args, *, env=None, stdin=None, stdout=None):
    subprocess.run(args, env=env, stdin=stdin, stdout=stdout, check=True)


def sha256_file(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def backup_target(profile, target):
    obj = object_name(profile, target["name"])
    with tempfile.TemporaryDirectory(prefix="shopvivaliz-db-backup-") as td:
        dump = Path(td) / "backup.dump"
        listing = Path(td) / "restore.list"
        manifest = Path(td) / "backup.sha256"
        if target.get("mode") == "container":
            with open(dump, "wb") as out:
                run_checked(["docker", "exec", target["container"], "pg_dump",
                             "-U", target["user"], "-d", target["database"],
                             "-Fc", "-Z", "3"], stdout=out)
        else:
            conn = connection_for(target)
            env = os.environ.copy()
            env["PGPASSWORD"] = conn["password"]
            cmd = [PG_DUMP, "-h", conn["host"], "-p", conn["port"], "-U", conn["user"],
                   "-d", conn["database"], "-Fc", "-Z", "3", "-f", str(dump)]
            run_checked(cmd, env=env)
        size = dump.stat().st_size
        if size < 4096:
            raise RuntimeError(f"dump unexpectedly small for {target['name']}: {size}")
        with open(listing, "w") as out:
            run_checked([PG_RESTORE, "-l", str(dump)], stdout=out)
        entries = sum(1 for line in listing.read_text().splitlines() if line and not line.startswith(";"))
        if entries < 5:
            raise RuntimeError(f"restore list too small for {target['name']}: {entries}")
        digest = sha256_file(dump)
        manifest.write_text(f"{digest}  {Path(obj).name}\n")
        put_object(dump, obj)
        put_object(manifest, obj + ".sha256")
        verify_remote_size(obj, size)
        print(f"backup_verified name={target['name']} bytes={size} restore_entries={entries} sha256={digest}")


def put_object(path, name):
    run_checked([OCI, "os", "object", "put", "--auth", "instance_principal",
                 "--namespace-name", NAMESPACE, "--bucket-name", BUCKET,
                 "--file", str(path), "--name", name, "--force"], stdout=subprocess.DEVNULL)


def verify_remote_size(name, expected):
    raw = subprocess.check_output([OCI, "os", "object", "head", "--auth", "instance_principal",
                                   "--namespace-name", NAMESPACE, "--bucket-name", BUCKET,
                                   "--name", name], text=True)
    data = json.loads(raw)["data"] if "data" in json.loads(raw) else json.loads(raw)
    actual = int(data.get("content-length") or data.get("content_length") or 0)
    if actual != expected:
        raise RuntimeError(f"remote size mismatch for {name}: local={expected} remote={actual}")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--profile", choices=sorted(PROFILES), required=True)
    parser.add_argument("--target")
    args = parser.parse_args()
    targets = targets_for(args.profile)
    if args.target:
        targets = [t for t in targets if t["name"] == args.target]
        if not targets:
            raise SystemExit(f"unknown target: {args.target}")
    for target in targets:
        backup_target(args.profile, target)


if __name__ == "__main__":
    main()
