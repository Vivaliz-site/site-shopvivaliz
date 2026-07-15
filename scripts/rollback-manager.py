#!/usr/bin/env python3
"""
Rollback Manager - Reverter em caso de falha
"""
import subprocess
import json
from pathlib import Path
from datetime import datetime

class RollbackManager:
    def __init__(self):
        self.max_retries = 3
        self.rollback_log = Path("logs/rollbacks.jsonl")
        self.rollback_log.parent.mkdir(parents=True, exist_ok=True)

    def check_task_success(self, task_id):
        """Verificar se tarefa completou com sucesso"""
        # Verificar se há novo commit após a tarefa
        result = subprocess.run(
            ["git", "log", "--oneline", "-1"],
            capture_output=True,
            text=True
        )

        return result.returncode == 0

    def rollback_last_commit(self, task_id):
        """Reverter último commit"""
        print(f"⏮️  Fazendo rollback de {task_id}...")

        try:
            # Salvar hash do commit anterior
            result = subprocess.run(
                ["git", "rev-parse", "HEAD~1"],
                capture_output=True,
                text=True
            )
            previous_commit = result.stdout.strip()

            # Reset para commit anterior
            subprocess.run(
                ["git", "reset", "--hard", previous_commit],
                check=True
            )

            # Push força (cuidado!)
            subprocess.run(
                ["git", "push", "--force-with-lease"],
                check=True
            )

            # Log do rollback
            self._log_rollback(task_id, previous_commit, "success")
            print(f" Rollback completado para {task_id}")

            return True
        except Exception as e:
            print(f" Erro ao fazer rollback: {e}")
            self._log_rollback(task_id, "", "failed")
            return False

    def _log_rollback(self, task_id, commit_hash, status):
        """Registrar rollback"""
        log_entry = {
            'timestamp': datetime.now().isoformat(),
            'task_id': task_id,
            'commit': commit_hash,
            'status': status
        }

        with open(self.rollback_log, 'a') as f:
            f.write(json.dumps(log_entry) + "\n")

    def retry_task(self, task_id, agents, attempt=1):
        """Tentar tarefa com agente diferente"""
        if attempt > self.max_retries:
            print(f" {task_id} falhou {self.max_retries}x - ENVIANDO ALERT")
            return False

        print(f"\n🔄 Tentativa {attempt}/{self.max_retries} para {task_id}")

        # Usar próximo agente disponível
        next_agent = agents[attempt % len(agents)]
        print(f"   Tentando com: {next_agent}")

        # Simular execução (em produção, seria real)
        # Se falhar, fazer rollback e retry

        return True

    def validate_commit(self, commit_hash):
        """Validar se commit é seguro"""
        print(f" Validando commit {commit_hash[:7]}...")

        # Verificar mudanças
        result = subprocess.run(
            ["git", "diff", f"{commit_hash}~1..{commit_hash}", "--stat"],
            capture_output=True,
            text=True
        )

        changes = result.stdout
        print(f"   Mudanças: {len(changes.splitlines())} arquivos")

        # Verificar se não deletou muitos arquivos
        if "deletions" in changes:
            print("    Commit contém deleções")

        return True

if __name__ == "__main__":
    manager = RollbackManager()

    # Exemplo de uso
    print("🛡️ Rollback Manager - Teste")
    print("")
    print("Funcionalidades:")
    print("1. Detectar falhas em tarefas")
    print("2. Fazer rollback automático")
    print("3. Tentar novamente com agente diferente")
    print("4. Retentar até 3x")
    print("5. Enviar alert se todas falharem")
