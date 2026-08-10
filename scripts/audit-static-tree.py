#!/usr/bin/env python3
from __future__ import annotations
import argparse, json, os, re, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SKIP_DIRS = {".git", "node_modules", "vendor", "storage", "logs", ".trash_claude_cleanup"}
PRESERVE_NAMES = {
    "tasks-queue.json",
    "tasks-queue-activate.json",
    "queue.sqlite",
    "products-cache-ativos.json",
}

def scan_files():
    for p in ROOT.rglob("*"):
        if any(part in SKIP_DIRS for part in p.parts):
            continue
        if p.is_file():
            yield p

def collect_refs():
    refs = {}
    patterns = [
        re.compile(r"[/\\\\]([A-Za-z0-9_.\-\/]+?\.(?:php|js|css|html|json|sh|py|txt|md))"),
        re.compile(r"['\"](\/[A-Za-z0-9_.\-\/]+)['\"]"),
    ]
    for p in scan_files():
        if p.suffix.lower() not in {".php", ".js", ".css", ".html", ".json", ".sh", ".py", ".md", ".txt", ".yml", ".yaml"}:
            continue
        try:
            text = p.read_text(encoding="utf-8", errors="ignore")
        except Exception:
            continue
        for pat in patterns:
            for m in pat.finditer(text):
                refs[Path(m.group(1)).name] = refs.get(Path(m.group(1)).name, 0) + 1
    return refs

def main():
    try:
        sys.stdout.reconfigure(encoding="utf-8")
    except Exception:
        pass
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()
    refs = collect_refs()
    orphans = []
    for p in scan_files():
        if p.name in PRESERVE_NAMES:
            continue
        if p.name.endswith(".bundle") or p.name.startswith("deploy-") or p.name.endswith(".log"):
            orphans.append(p)
            continue
        if p.suffix.lower() in {".php", ".js", ".css", ".html", ".sh", ".py"} and refs.get(p.name, 0) == 0:
            orphans.append(p)
    print(json.dumps({"dry_run": args.dry_run, "orphans": [str(p.relative_to(ROOT)) for p in sorted(set(orphans))]}, indent=2, ensure_ascii=False))
    if not args.dry_run:
        for p in sorted(set(orphans)):
            try:
                p.unlink()
            except Exception:
                pass

if __name__ == "__main__":
    main()
