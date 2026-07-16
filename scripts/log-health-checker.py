import os
from pathlib import Path
from datetime import datetime, timedelta

def check_log_status():
    log_files = {
        "logs/execution": {"type": "dir", "description": "Logs de Execução"},
        "logs/monitor-messages.log": {"type": "file", "description": "Mensagens do Monitor"},
        "logs/monitor-responses.jsonl": {"type": "file", "description": "Respostas dos Agentes"},
    }

    report = {
        "timestamp": datetime.now().isoformat(),
        "status": "HEALTHY",
        "log_checks": {},
        "errors": [],
        "warnings": [],
    }

    print("\n--- Verificação de Saúde dos Logs ---")

    for path_str, config in log_files.items():
        path = Path(path_str)
        desc = config["description"]
        check_result = {"exists": False, "empty": True, "recent_errors": False, "details": ""}

        if not path.exists():
            check_result["details"] = "Ausente"
            report["warnings"].append(f"Log {desc} ({path_str}) está ausente.")
        else:
            check_result["exists"] = True
            if config["type"] == "dir":
                files = list(path.iterdir())
                if not files:
                    check_result["details"] = "Vazio (diretório sem arquivos)"
                    report["warnings"].append(f"Diretório de log {desc} ({path_str}) está vazio.")
                else:
                    check_result["empty"] = False
                    check_result["details"] = f"Contém {len(files)} arquivos."
            elif config["type"] == "file":
                size = path.stat().st_size
                if size == 0:
                    check_result["details"] = "Vazio (arquivo 0 bytes)"
                    report["warnings"].append(f"Arquivo de log {desc} ({path_str}) está vazio.")
                else:
                    check_result["empty"] = False
                    check_result["details"] = f"Tamanho: {size} bytes."
                    # Tenta buscar erros recentes (ultimos 10 minutos)
                    try:
                        with open(path, "r", encoding="utf-8", errors="ignore") as f:
                            lines = f.readlines()
                            # Apenas as últimas 100 linhas para performance
                            recent_lines = lines[-100:]
                            for line in recent_lines:
                                if "[ERROR]" in line or "[ERRO]" in line:
                                    timestamp_str = line.split(" ")[0].replace("[", "")
                                    try:
                                        log_time = datetime.fromisoformat(timestamp_str)
                                        if datetime.now() - log_time < timedelta(minutes=10):
                                            check_result["recent_errors"] = True
                                            report["errors"].append(f"Erros recentes encontrados no log {desc} ({path_str}).")
                                            break
                                    except ValueError:
                                        # Formato de timestamp inválido, ignora
                                        pass
                    except Exception as e:
                        report["warnings"].append(f"Não foi possível ler {path_str} para erros: {e}")
        
        report["log_checks"][path_str] = check_result
        print(f"  [STATUS] {desc:<25}: {check_result['details']}")

    if report["errors"]:
        report["status"] = "CRITICAL"
    elif report["warnings"]:
        report["status"] = "WARNING"

    print(f"\nStatus Geral dos Logs: {report['status']}")
    if report["errors"]:
        print("Erros:")
        for err in report["errors"]:
            print(f"  - {err}")
    if report["warnings"]:
        print("Avisos:")
        for warn in report["warnings"]:
            print(f"  - {warn}")

    # Salvar relatório (opcional, pode ser integrado ao system-health-check.py)
    report_dir = Path("logs")
    report_dir.mkdir(parents=True, exist_ok=True)
    with open(report_dir / "log-health-check-report.json", "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2, ensure_ascii=False)
    
    print(f"Relatório de logs salvo em: {report_dir / 'log-health-check-report.json'}")

    return report

if __name__ == "__main__":
    import json
    check_log_status()
