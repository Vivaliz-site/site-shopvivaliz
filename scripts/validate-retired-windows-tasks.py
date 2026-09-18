#!/usr/bin/env python3
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]

checks = [
    (ROOT / "ATIVAR-AUTO-SYNC.bat", "RETIRADO", ("schtasks /create",)),
    (ROOT / "scripts" / "install-auto-sync-pc.ps1", "RETIRADO", ("Register-ScheduledTask",)),
]

errors = []
for path, required, forbidden in checks:
    text = path.read_text(encoding="utf-8", errors="replace")
    if required not in text:
        errors.append(f"{path.relative_to(ROOT)}: marcador RETIRADO ausente")
    for token in forbidden:
        if token.lower() in text.lower():
            errors.append(f"{path.relative_to(ROOT)}: instalador legado ainda cria tarefa")

monitor = (ROOT / "scripts" / "routine-abandonment-monitor.ps1").read_text(encoding="utf-8", errors="replace")
if "'ShopVivaliz Auto Sync'" in monitor:
    errors.append("routine-abandonment-monitor.ps1: Auto Sync legado ainda esta na allowlist")

if errors:
    for error in errors:
        print("ERROR retired-windows-task-policy:", error, file=sys.stderr)
    raise SystemExit(1)

print("OK retired Windows tasks: legacy auto-sync cannot be recreated")
