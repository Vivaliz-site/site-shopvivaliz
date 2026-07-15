#!/usr/bin/env python3
"""Gerar relatório de execução do Trio IA"""
import json
from datetime import datetime
from pathlib import Path

queue_file = Path("logs/tasks-queue.json")

if queue_file.exists():
    with open(queue_file, "r", encoding="utf-8") as f:
        data = json.load(f)
    tasks = data.get("queue", [])
else:
    print(f"⚠️  {queue_file} não encontrado. Gerando relatório vazio.")
    tasks = []

completed = len([t for t in tasks if t["status"] == "completed"])
pending = len([t for t in tasks if t["status"] == "pending"])
total = len(tasks)

report = f"""
📊 RELATÓRIO - Trio IA Autônomo
{'='*60}
Data/Hora: {datetime.utcnow().isoformat()}

STATUS DA FILA:
  Total: {total}
  ✅ Completas: {completed}
  ⏳ Pendentes: {pending}
  Taxa: {(completed/total*100) if total > 0 else 0:.1f}%

ÚLTIMAS COMPLETAS:
"""

for task in [t for t in tasks if t["status"] == "completed"][-5:]:
    report += f"  • {task['id']} - {task['title']}\n"

report += "\nPRÓXIMAS PENDENTES:\n"

for task in [t for t in tasks if t["status"] == "pending"][:5]:
    report += f"  • {task['id']} - {task['title']} ({task.get('priority','normal')})\n"

report += f"\n🔗 GitHub: https://github.com/fredmourao-ai/site-shopvivaliz\n"

# Salvar
Path("/tmp/trio-report.txt").write_text(report, encoding="utf-8")
print(report)
