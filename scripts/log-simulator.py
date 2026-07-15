import os
import json
from datetime import datetime, timedelta
from pathlib import Path
import random

def generate_log_entry(level, message, context=None):
    timestamp = datetime.now().isoformat()
    entry = {
        "timestamp": timestamp,
        "level": level,
        "message": message,
        "context": context if context is not None else {}
    }
    return json.dumps(entry, ensure_ascii=False)

def simulate_logs(num_entries=5):
    log_dir = Path("logs")
    log_dir.mkdir(parents=True, exist_ok=True)

    execution_dir = log_dir / "execution"
    execution_dir.mkdir(parents=True, exist_ok=True)
    execution_log_path = execution_dir / "app.log"
    monitor_messages_log_path = log_dir / "monitor-messages.log"
    monitor_responses_jsonl_path = log_dir / "monitor-responses.jsonl"

    # Limpar logs existentes para simulação
    for log_path_to_clean in [execution_log_path, monitor_messages_log_path, monitor_responses_jsonl_path]:
        if log_path_to_clean.exists() and log_path_to_clean.is_file():
            log_path_to_clean.unlink()
    # Limpar arquivos dentro do diretório de execução, se for um diretório
    if execution_dir.exists() and execution_dir.is_dir():
        for file in execution_dir.iterdir():
            if file.is_file():
                file.unlink()
    
    print("\n--- Simulação de Geração de Logs ---")
    print(f"Gerando {num_entries} entradas de log...")

    for i in range(num_entries):
        # Log para logs/execution
        level = random.choice(["INFO", "WARNING", "ERROR"])
        message = f"Simulação de execução: Evento {i+1} de {num_entries}."
        with open(execution_log_path, "a", encoding="utf-8") as f:
            f.write(f"[{datetime.now().isoformat()}] [{level}] {message}\n")

        # Log para logs/monitor-messages.log
        message = f"Monitor message: Verificação de sistema concluída. Status OK."
        if i == 2:
            level = "WARNING"
            message = "Monitor message: Alerta: Um componente não respondeu a tempo."
        if i == 4:
            level = "ERROR"
            message = "Monitor message: Erro crítico: Falha na conexão com o banco de dados."
        with open(monitor_messages_log_path, "a", encoding="utf-8") as f:
            f.write(f"[{datetime.now().isoformat()}] [{level}] {message}\n")
        
        # Log para logs/monitor-responses.jsonl
        response_data = {
            "task_id": f"task_{i+1}",
            "agent": random.choice(["OlistSyncAgent", "ImageOptimizationAgent", "QASelfTestAgent"]),
            "status": random.choice(["completed", "failed", "pending"]),
            "details": f"Processamento do item {i+1}."
        }
        if response_data["status"] == "failed":
            response_data["error"] = "Falha ao processar imagem."
        with open(monitor_responses_jsonl_path, "a", encoding="utf-8") as f:
            f.write(generate_log_entry("INFO", "Agent response simulated", response_data) + "\n")

    print(f"Logs simulados gerados nos arquivos:")
    print(f"- {execution_dir}/app.log (diretório e arquivo)")
    print(f"- {monitor_messages_log_path}")
    print(f"- {monitor_responses_jsonl_path}")

if __name__ == "__main__":
    simulate_logs()