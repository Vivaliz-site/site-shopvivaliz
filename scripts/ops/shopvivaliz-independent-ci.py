#!/usr/bin/env python3
"""Independent, read-only, fail-closed CI for allowlisted ShopVivaliz public repos.

No GitHub API, PAT, GitHub Actions, status updates, merges, or production secrets.
Runs untrusted repository code ONLY inside a locked-down offline Docker container.
Never claims a local result is a required GitHub check or production certification.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import uuid

ROOT = Path("/var/lib/shopvivaliz-independent-ci")
IMAGE = "node@sha256:18a6d90fd60542269850c80ca03b29b1d9b14d6b221ac7ee8e35b0d33ef904bd"
TARGETS = {
    # This repo is explicitly frozen/superseded. Certify containment, not
    # non-existent current-site agent adapters or production readiness.
    "legacy": ("Vivaliz-site/solange-rolla", [
        ("legacy-containment",
         "grep -Fq 'REPOSITÓRIO HISTÓRICO / SUPERSEDIDO' README.md && "
         "grep -Fq 'solange-rolla-consultorio' README.md && "
         "for workflow in .github/workflows/ci.yml .github/workflows/repository-governance.yml; do "
         "grep -Fq 'github.event.pull_request.head.repo.full_name == github.repository' \"$workflow\" || exit 1; "
         "if grep -Eq '^[[:space:]]*push:' \"$workflow\"; then exit 1; fi; "
         "done"),
        ("continuity-policy", "bash scripts/agent-continuity-validate.sh"),
        ("javascript-contract", "node tests/workflow-continuity.test.mjs && node --check app.js"),
        ("audit-selftest", "python3 -m unittest tests.test_certify_audit_manifest -q"),
        ("merge-provenance", "python3 -m unittest tests.test_validate_main_merge_provenance -q"),
    ]),
    "consultorio": ("Vivaliz-site/solange-rolla-consultorio", [
        ("audit-ledger-contract", "python3 -m unittest tests.test_validate_audit_ledger_delta -q"),
        ("global-audit-selftest", "python3 -m unittest tests.test_certify_audit_manifest -q"),
        ("global-audit-governance", "python3 scripts/validate-audit-governance.py --output-dir /tmp/local-governance"),
    ]),
    "site": ("Vivaliz-site/site-shopvivaliz", [
        ("agent-checklist", "python3 -m unittest tests.test_mandatory_agent_checklist -q"),
        ("continuity-v8", "python3 -m unittest tests.test_global_task_continuity_v8 -q"),
    ]),
}
# Previous, publicly redirected remotes are allowed only for a one-time,
# explicit org-migration reconciliation. Never rewrite an unexpected remote.
RETIRED_MIRROR_REMOTES = {
    "legacy": "https://github.com/fredmourao-ai/solange-rolla.git",
    "consultorio": "https://github.com/fredmourao-ai/solange-rolla-consultorio.git",
}
ALLOWED_REF = re.compile(r"^(refs/heads/[A-Za-z0-9][A-Za-z0-9._/-]{0,160}|refs/pull/[1-9][0-9]{0,7}/head)$")
SHA = re.compile(r"^[a-f0-9]{40}$")
BASE_ENV = {
    "PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
    "HOME": "/",
    "LC_ALL": "C",
    "GIT_CONFIG_SYSTEM": "/dev/null",
    "GIT_CONFIG_GLOBAL": "/dev/null",
    "GIT_TERMINAL_PROMPT": "0",
    "GIT_ASKPASS": "/bin/false",
    "SSH_ASKPASS": "/bin/false",
}
def call(args, timeout=120, cwd=None):
    return subprocess.run(args, cwd=cwd, env=BASE_ENV, stdin=subprocess.DEVNULL,
                          capture_output=True, text=True, timeout=timeout, check=False)

def ensure_mirror(target, repo):
    d = ROOT / "mirrors" / (target + ".git")
    canonical = "https://github.com/" + repo + ".git"
    if not d.exists():
        d.parent.mkdir(parents=True, exist_ok=True)
        x = call(["git", "init", "--bare", str(d)], timeout=15)
        if x.returncode:
            raise RuntimeError("git init bare failed: " + x.stderr[-400:])
        x = call(["git", "--git-dir", str(d), "remote", "add", "origin",
                  canonical], timeout=15)
        if x.returncode:
            raise RuntimeError("git remote setup failed: " + x.stderr[-400:])
    else:
        x = call(["git", "--git-dir", str(d), "remote", "get-url", "origin"], timeout=15)
        if x.returncode:
            raise RuntimeError("git mirror origin unavailable")
        current = x.stdout.strip()
        if current != canonical:
            if current != RETIRED_MIRROR_REMOTES.get(target):
                raise RuntimeError("untrusted mirror origin; refusing remote mutation")
            x = call(["git", "--git-dir", str(d), "remote", "set-url", "origin", canonical], timeout=15)
            if x.returncode:
                raise RuntimeError("canonical mirror origin update failed")
            verified = call(["git", "--git-dir", str(d), "remote", "get-url", "origin"], timeout=15)
            if verified.returncode or verified.stdout.strip() != canonical:
                raise RuntimeError("canonical mirror origin readback mismatch")
    return d

def fetch_source(mirror, target, ref, offline):
    dst = "refs/independent-ci/" + hashlib.sha256(ref.encode()).hexdigest()[:16]
    fresh = False
    note = "offline requested"
    if not offline:
        x = call(["git", "--git-dir", str(mirror), "-c", "credential.helper=",
                  "fetch", "--no-tags", "--depth=96", "origin", "+" + ref + ":" + dst], 180)
        fresh = x.returncode == 0
        note = "upstream fetched" if fresh else ("upstream unavailable: " + (x.stderr or "")[-180:])
    z = call(["git", "--git-dir", str(mirror), "rev-parse", "--verify", dst + "^{commit}"], 12)
    if z.returncode or not SHA.fullmatch(z.stdout.strip()):
        raise RuntimeError("No cached ref; cannot run without verified commit")
    return z.stdout.strip(), fresh, note

def run_step(step_name, command, worktree, target):
    container = "shopv-ci-" + target + "-" + uuid.uuid4().hex[:12]
    cmd = [
        "docker", "run", "--rm", "--pull=never", "--name", container,
        "--network", "none", "--read-only", "--cap-drop", "ALL",
        "--security-opt", "no-new-privileges",
        "--pids-limit", "80", "--memory", "768m", "--cpus", "0.75",
        "--user", "65534:65534",
        "--tmpfs", "/tmp:rw,nosuid,nodev,size=96m,mode=1777",
        "--env", "HOME=/tmp", "--env", "PYTHONDONTWRITEBYTECODE=1",
        "--env", "GIT_CONFIG_COUNT=1", "--env", "GIT_CONFIG_KEY_0=safe.directory",
        "--env", "GIT_CONFIG_VALUE_0=/work",
        "--env", "GITHUB_TOKEN=", "--env", "GH_TOKEN=",
        "--mount", "type=bind,source=" + str(worktree) + ",target=/work,readonly",
        "--workdir", "/work",
        IMAGE, "timeout", "-k", "3", "110", "bash", "-c", "set -Eeuo pipefail; " + command
    ]
    start = time.monotonic()
    try:
        p = call(cmd, timeout=140)
        output = (p.stdout or "") + "\n" + (p.stderr or "")
        code = p.returncode
    except subprocess.TimeoutExpired:
        call(["docker", "rm", "-f", container], timeout=15)
        output, code = "container step timed out; force-removal attempted", 124
    return {
        "step": step_name, "exit_code": code, "duration_seconds": round(time.monotonic()-start, 3),
        "ok": code == 0, "output_tail": output[-3200:],
        "output_sha256": hashlib.sha256(output.encode()).hexdigest()
    }

def prepare_public_checkout_for_isolated_tests(checkout: Path):
    """Make ONLY the ephemeral public checkout readable by Docker UID 65534.

    The service's UMask=0077 causes git to materialize code as 0600 and
    directories as 0700, while the container deliberately runs as nobody.
    Keep the host parent/workspace at 0700 and the Docker mount read-only.
    This never changes the mirror, system paths, secrets or outside symlinks.
    """
    if checkout.is_symlink() or not checkout.is_dir():
        raise RuntimeError("checkout_root_not_a_real_directory")
    for current, dirs, files in os.walk(checkout, topdown=True, followlinks=False):
        folder = Path(current)
        if folder.is_symlink() or not folder.is_dir():
            raise RuntimeError("checkout_contains_unexpected_directory")
        # Owner-only host parent is retained; the container sees only /work.
        os.chmod(folder, 0o755)
        for childname in list(dirs):
            child = folder / childname
            if child.is_symlink():
                dirs.remove(childname)
            elif not child.is_dir():
                raise RuntimeError("checkout_contains_unexpected_child")
        for filename in files:
            child = folder / filename
            attrs = child.lstat()
            if stat.S_ISLNK(attrs.st_mode):
                continue
            if not stat.S_ISREG(attrs.st_mode):
                raise RuntimeError("checkout_contains_special_file")
            # Preserve executable bit for Git scripts; never make data writable
            # by nonowners. All files come from a public, exact-SHA checkout.
            os.chmod(child, 0o755 if (attrs.st_mode & 0o111) else 0o644)


def run_target(target, ref, offline=False, expected_sha=None):
    repo, steps = TARGETS[target]
    ref = ref or "refs/heads/main"
    if not ALLOWED_REF.fullmatch(ref) or ".." in ref or "//" in ref or ref.endswith(".lock"):
        raise ValueError("unsafe or unsupported git ref")
    mirror = ensure_mirror(target, repo)
    commit, fresh, note = fetch_source(mirror, target, ref, offline)
    if expected_sha and (not SHA.fullmatch(expected_sha) or expected_sha != commit):
        raise ValueError("commit differs from expected SHA; fail closed")
    runid = dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%S%fZ") + "-" + uuid.uuid4().hex[:6]
    results = []
    workspace = ROOT / "workspaces"
    workspace.mkdir(parents=True, exist_ok=True)
    scratch = Path(tempfile.mkdtemp(prefix=target+"-", dir=workspace))
    try:
        checkout = scratch / "checkout"
        x = call(["git", "init", "--quiet", str(checkout)], 15)
        if x.returncode: raise RuntimeError("local checkout init failed: " + x.stderr[-400:])
        x = call(["git", "-C", str(checkout), "fetch", "--quiet", "--no-tags",
                  str(mirror), commit], 120)
        if x.returncode: raise RuntimeError("local mirror fetch failed: " + x.stderr[-400:])
        x = call(["git", "-C", str(checkout), "checkout", "--quiet", "--detach", commit], 45)
        if x.returncode: raise RuntimeError("commit checkout failed: " + x.stderr[-400:])
        prepare_public_checkout_for_isolated_tests(checkout)
        for step, command in steps:
            results.append(run_step(step, command, checkout, target))
    finally:
        shutil.rmtree(scratch, ignore_errors=True)
    verdict = "FAILED_TEST" if any(not x["ok"] for x in results) else (
        "PASS_LOCAL_NOT_GITHUB_CHECK" if fresh else "STALE_SOURCE_LOCAL_PASS_ONLY")
    if target == "legacy" and verdict != "FAILED_TEST":
        # This does NOT certify the old UI, production, or global V5 audit.
        verdict = "PASS_LEGACY_CONTAINMENT_ONLY" if fresh else "STALE_LEGACY_CONTAINMENT_ONLY"
    report = {
        "schema": "SHOPVIVALIZ_INDEPENDENT_CI_V1",
        "run_id": runid, "timestamp_utc": dt.datetime.now(dt.timezone.utc).isoformat(),
        "target": target, "repository": repo, "ref": ref, "commit_sha": commit,
        "source_fresh": fresh, "source_note": note, "image": IMAGE,
        "network": "none", "user": "65534:65534", "host_production_access": False,
        "git_personal_token_used": False,
        "checks": results, "verdict": verdict,
        "github_branch_protection_unchanged": True,
        "github_required_checks_satisfied_by_this_report": False,
        "certifies_global_audit_or_production": False,
    }
    path = ROOT / "evidence" / target
    path.mkdir(parents=True, exist_ok=True)
    evidence = path / (runid + ".json")
    payload = json.dumps(report, ensure_ascii=False, indent=2) + "\n"
    temp = path / ("." + runid + ".tmp")
    temp.write_text(payload, encoding="utf-8")
    os.replace(temp, evidence)
    (path / "latest.json").write_text(payload, encoding="utf-8")
    print(json.dumps({"target":target,"ref":ref,"sha":commit,"source_fresh":fresh,
                      "verdict":verdict,"evidence":str(evidence),
                      "failed_steps":[x["step"] for x in results if not x["ok"]]}))
    return verdict in {"PASS_LOCAL_NOT_GITHUB_CHECK", "PASS_LEGACY_CONTAINMENT_ONLY"}

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--target", choices=sorted(TARGETS))
    p.add_argument("--ref", default=None)
    p.add_argument("--sha", default=None)
    p.add_argument("--offline", action="store_true")
    p.add_argument("--self-test", action="store_true")
    a = p.parse_args()
    if a.self_test:
        assert not ALLOWED_REF.fullmatch("refs/heads/../main")
        assert ALLOWED_REF.fullmatch("refs/pull/34/head")
        assert set(TARGETS) == {"legacy","consultorio","site"}
        print("SELF_TEST=PASS: ref allowlist, repo allowlist and fail-closed contract")
        return 0
    if a.ref and not a.target: p.error("--ref requires --target")
    if a.sha and not a.target: p.error("--sha requires --target")
    failed = False
    for target in ([a.target] if a.target else TARGETS):
        try:
            if not run_target(target, a.ref, a.offline, a.sha): failed=True
        except (ValueError,RuntimeError,subprocess.TimeoutExpired,OSError) as error:
            failed=True
            print(json.dumps({"target":target,"verdict":"BLOCKED_EXTERNAL_OR_INTEGRITY",
                              "error":str(error)[:450]}), file=sys.stderr)
    return 1 if failed else 0
if __name__ == "__main__":
    sys.exit(main())
