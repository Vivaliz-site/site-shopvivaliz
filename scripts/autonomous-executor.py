#!/usr/bin/env python3
"""Executor autônomo do Trio IA"""
import json
import subprocess
import sys
from datetime import datetime
from pathlib import Path

def main():
    queue_file = Path("tasks-queue.json")

    # Ler fila de tarefas
    with open(queue_file, "r", encoding="utf-8") as f:
        queue_data = json.load(f)

    # Encontrar primeira tarefa pendente
    pending_tasks = [t for t in queue_data["queue"] if t["status"] == "pending"]

    if not pending_tasks:
        print("✅ Nenhuma tarefa pendente. Fila vazia ou todas completas!")
        return 0

    task = pending_tasks[0]
    task_id = task["id"]
    task_title = task["title"]
    task_desc = task["description"]

    print(f"🎯 Executando tarefa: {task_title}")
    print(f"   ID: {task_id}")
    print(f"   Descrição: {task_desc[:100]}...")
    print()

    # Executar Trio IA com a tarefa
    try:
        result = subprocess.run(
            [
                "python",
                "ai_collaboration.py",
                "--modo",
                "ecommerce",
                "--tarefa",
                f"{task_title}: {task_desc}",
            ],
            capture_output=True,
            text=True,
            timeout=600,
        )

        if result.returncode == 0:
            print("✅ Tarefa executada com sucesso!")

            # Marcar tarefa como completa
            task["status"] = "completed"
            task["completed_at"] = datetime.utcnow().isoformat() + "Z"

            # Salvar fila atualizada
            with open(queue_file, "w", encoding="utf-8") as f:
                json.dump(queue_data, f, indent=2, ensure_ascii=False)

            # Commit das mudanças
            subprocess.run(["git", "add", "-A"], check=True)

            # Verificar se há mudanças
            diff_result = subprocess.run(
                ["git", "diff", "--cached", "--quiet"], capture_output=True
            )

            if diff_result.returncode != 0:
                subprocess.run(
                    [
                        "git",
                        "commit",
                        "-m",
                        f"feat: {task_title}\n\nExecutado automaticamente pelo Trio IA\nTask ID: {task_id}\n\nCo-Authored-By: Trio IA Autônomo <trio-autonomo@shopvivaliz.com.br>",
                    ],
                    check=True,
                )
                subprocess.run(["git", "push", "origin", "HEAD:main"], check=True)
                print("📤 Código commitado e publicado em main")
                print("🚀 Deploy acionado automaticamente!")
            else:
                print("ℹ️  Nenhuma mudança de código para commitar")

            print(f"📋 Tarefa {task_id} marcada como completa")
            print()
            print("⏭️  Próxima execução em 1 hora")
            return 0
        else:
            print(f"❌ Erro ao executar: {result.stderr}")
            return 1

    except subprocess.TimeoutExpired:
        print("⏱️  Timeout na execução (>10 min). Tentando novamente na próxima hora.")
        return 1
    except Exception as e:
        print(f"❌ Erro: {e}")
        return 1

if __name__ == "__main__":
    sys.exit(main())
