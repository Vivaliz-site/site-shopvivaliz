from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

BRANCH = "ops/inspect-mei-v020-stash"
REPO = Path(r"C:\site-shopvivaliz")
WORK = Path(r"C:\Temp\shopvivaliz-exchange-refresh")
SCRIPT = WORK / "fredwin-exchange-refresh-token-unblock.ps1"


def out(message: str) -> None:
    print(message.encode("cp1252", errors="replace").decode("cp1252"), flush=True)


def run() -> int:
    WORK.mkdir(parents=True, exist_ok=True)
    try:
        fetch = subprocess.run(
            ["git", "-C", str(REPO), "fetch", "origin", BRANCH],
            text=True,
            capture_output=True,
            timeout=120,
            check=False,
        )
        if fetch.returncode != 0:
            out("EXCHANGE_UI_ERROR=FREDWIN_GIT_FETCH_FAILED")
            return 20

        shown = subprocess.run(
            [
                "git",
                "-C",
                str(REPO),
                "show",
                f"origin/{BRANCH}:scripts/fredwin-exchange-refresh-token-unblock.ps1",
            ],
            text=True,
            capture_output=True,
            timeout=60,
            check=False,
        )
        if shown.returncode != 0 or not shown.stdout.strip():
            out("EXCHANGE_UI_ERROR=FREDWIN_REFRESH_SCRIPT_EXPORT_FAILED")
            return 21
        SCRIPT.write_text(shown.stdout, encoding="utf-8")
        out("EXCHANGE_DELEGATED_SCRIPT_READY=true")

        result = subprocess.run(
            [
                "powershell.exe",
                "-NoProfile",
                "-ExecutionPolicy",
                "Bypass",
                "-File",
                str(SCRIPT),
            ],
            text=True,
            capture_output=True,
            timeout=780,
            check=False,
        )
        stdout = result.stdout or ""
        stderr = result.stderr or ""
        for line in stdout.splitlines():
            out(line)
        if result.returncode == 0 and "EXCHANGE_DELEGATED_UNBLOCK_CONFIRMED=true" in stdout:
            out("EXCHANGE_UI_UNBLOCK_CONFIRMED")
            return 0

        detail = " ".join(stderr.replace("\r", " ").replace("\n", " ").split())
        if not detail:
            detail = f"powershell_rc={result.returncode}"
        out("EXCHANGE_UI_ERROR=" + detail[:900])
        return result.returncode or 22
    except subprocess.TimeoutExpired:
        out("EXCHANGE_UI_TIMEOUT=delegated_admin_recovery")
        return 23
    except Exception as exc:
        out("EXCHANGE_UI_ERROR=" + type(exc).__name__ + ":" + str(exc).replace("\n", " ")[:700])
        return 24


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=("probe", "unblock"), default="unblock")
    parser.parse_args()
    return run()


if __name__ == "__main__":
    raise SystemExit(main())
