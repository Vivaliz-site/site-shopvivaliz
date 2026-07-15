#!/usr/bin/env python3
"""
SYSTEM HEALTH CHECK - Verificação completa de funcionalidade
"""
import os
import json
from pathlib import Path
from datetime import datetime
from task_queue_lib import load_queue

class SystemHealthCheck:
    def __init__(self):
        self.report = {
            'timestamp': datetime.now().isoformat(),
            'status': 'UNKNOWN',
            'checks': {},
            'errors': [],
            'warnings': []
        }

    def check_files_exist(self):
        """Verificar se arquivos críticos existem"""
        print("\n1. VERIFICANDO ARQUIVOS CRÍTICOS...")

        critical_files = {
            'index.php': 'Homepage',
            'config/constants.php': 'Constantes',
            'config/database.php': 'Banco de dados',
            '.htaccess': 'Segurança',
            '.env.example': 'Template ENV',
            'tasks-queue.json': 'Fila de tarefas canônica',
            'api/monitor/api.php': 'API Monitor',
            '.github/workflows/24-7-continuous-agent.yml': 'Workflow 24/7',
            'scripts/tri-environment-sync.js': 'Runner triambiente JS',
            'scripts/automation/validate_email_config.py': 'Validador email'
        }

        all_exist = True
        for file, desc in critical_files.items():
            exists = Path(file).exists()
            status = "OK" if exists else "FALTA"
            print(f"  [{status}] {file:<40} - {desc}")
            if not exists:
                all_exist = False
                self.report['errors'].append(f"Arquivo faltando: {file}")

        self.report['checks']['critical_files'] = all_exist
        return all_exist

    def check_queue_status(self):
        """Verificar fila de tarefas"""
        print("\n2. VERIFICANDO FILA DE TAREFAS...")

        try:
            queue = load_queue()
            tasks = queue.get('queue', [])
            if not isinstance(tasks, list):
                raise ValueError('Formato de fila invalido')

            total = len(tasks)
            completed = len([t for t in tasks if t['status'] == 'completed'])
            pending = len([t for t in tasks if t['status'] == 'pending'])

            print(f"  Total de tarefas: {total}")
            print(f"  Completadas: {completed} ({100*completed/total:.1f}%)")
            print(f"  Pendentes: {pending} ({100*pending/total:.1f}%)")

            status_ok = total > 0
            self.report['checks']['task_queue'] = {
                'total': total,
                'completed': completed,
                'pending': pending,
                'ok': status_ok
            }

            return status_ok
        except Exception as e:
            print(f"  [ERRO] {e}")
            self.report['errors'].append(f"Erro ao ler fila: {str(e)}")
            return False

    def check_logs_exist(self):
        """Verificar se logs estão sendo gerados"""
        print("\n3. VERIFICANDO LOGS...")

        log_files = {
            'logs/execution/app.log': 'Logs de execução',
            'logs/monitor-messages.log': 'Mensagens do monitor',
            'logs/monitor-responses.jsonl': 'Respostas dos agentes',
            'logs/autonomous-cycle-events.jsonl': 'Rastro dos ciclos autônomos',
            'logs/autonomous-cycle-report.json': 'Relatório do ciclo autônomo',
            'logs/autonomous-cycle-report.md': 'Relatório humano do ciclo',
            'logs/tri-environment-sync.json': 'Sincronização triambiente',
        }

        all_exist = False
        for log_path, desc in log_files.items():
            exists = Path(log_path).exists()
            if exists:
                if Path(log_path).is_dir():
                    count = len(list(Path(log_path).glob('*')))
                    print(f"  [OK] {log_path:<40} - {count} arquivos")
                else:
                    size = Path(log_path).stat().st_size / 1024
                    print(f"  [OK] {log_path:<40} - {size:.1f} KB")
                all_exist = True
            else:
                print(f"  [VAZIO] {log_path:<40}")

        self.report['checks']['logs'] = all_exist
        return all_exist

    def check_api_responses(self):
        """Verificar respostas de APIs"""
        print("\n4. VERIFICANDO APIs...")

        api_endpoints = {
            'api/monitor/api.php?action=status': 'Status do sistema',
            'api/monitor/api.php?action=tasks': 'Lista de tarefas',
            'api/monitor/api.php?action=logs': 'Logs'
        }

        api_ok = False
        try:
            # Testar se arquivo API existe e é PHP válido
            api_file = Path('api/monitor/api.php')
            if api_file.exists():
                print(f"  [OK] API Monitor existe")

                with open(api_file) as f:
                    content = f.read()
                    if (
                        '<?php' in content
                        and 'monitor_response' in content
                        and "case 'agents'" in content
                        and "case 'send-command'" in content
                    ):
                        print("  [OK] API Monitor schema atual reconhecido")
                        api_ok = True
                    else:
                        self.report['warnings'].append(
                            "API Monitor encontrada, mas schema atual nao foi reconhecido pelo health check"
                        )
            else:
                self.report['errors'].append("API Monitor não encontrada")
        except Exception as e:
            self.report['errors'].append(f"Erro ao verificar API: {str(e)}")

        self.report['checks']['api'] = api_ok
        return api_ok

    def check_workflows(self):
        """Verificar workflows GitHub Actions"""
        print("\n5. VERIFICANDO WORKFLOWS...")

        workflows = [
            '.github/workflows/24-7-continuous-agent.yml',
            '.github/workflows/deploy.yml',
            '.github/workflows/monitor-chat-responses.yml',
            '.github/workflows/parallel-trio-executor.yml'
        ]

        all_exist = True
        for workflow in workflows:
            exists = Path(workflow).exists()
            status = "OK" if exists else "FALTA"
            print(f"  [{status}] {workflow}")
            if not exists:
                all_exist = False
                self.report['errors'].append(f"Workflow faltando: {workflow}")

        self.report['checks']['workflows'] = all_exist
        return all_exist

    def check_config_files(self):
        """Verificar arquivos de configuração"""
        print("\n6. VERIFICANDO CONFIGURAÇÕES...")

        configs = {
            'config/constants.php': 'Constantes',
            'config/database.php': 'Banco de dados',
            '.env.example': 'Template ENV',
            'AUDITORIA-ESTRUTURA.md': 'Documentação auditoria',
            'AGENTS-ACCESS-INDEX.md': 'Índice de agentes'
        }

        all_ok = True
        for config, desc in configs.items():
            exists = Path(config).exists()
            status = "OK" if exists else "FALTA"
            print(f"  [{status}] {config:<40} - {desc}")
            if not exists:
                all_ok = False

        self.report['checks']['config_files'] = all_ok
        return all_ok

    def check_agent_status(self):
        """Verificar status dos agentes"""
        print("\n7. VERIFICANDO STATUS DOS AGENTES...")

        agent_scripts = {
            'scripts/real-task-executor.py': 'Executor real',
            'scripts/chat-responder.py': 'Responder chat',
            'scripts/force-execution.py': 'Força de execução',
            'scripts/continuous-executor.py': 'Executor contínuo',
            'scripts/tri-environment-sync.js': 'Sincronização triambiente JS'
        }

        all_ok = True
        for script, desc in agent_scripts.items():
            exists = Path(script).exists()
            status = "OK" if exists else "FALTA"
            print(f"  [{status}] {script:<40} - {desc}")
            if not exists:
                all_ok = False

        self.report['checks']['agent_scripts'] = all_ok
        return all_ok

    def check_documentation(self):
        """Verificar documentação"""
        print("\n8. VERIFICANDO DOCUMENTAÇÃO...")

        docs = {
            'README.md': 'README',
            'AGENTS-ACCESS-INDEX.md': 'Índice agentes',
            'AUDITORIA-ESTRUTURA.md': 'Auditoria',
            'AGENTS-STATUS-REPORT.md': 'Status agentes',
            'OLIST-IMAGES-IMPORT-PLAN.md': 'Plano Olist',
            'DEPLOY-TROUBLESHOOTING.md': 'Troubleshooting',
            'docs/email-secrets-aliases.md': 'Aliases email'
        }

        all_ok = True
        for doc, desc in docs.items():
            exists = Path(doc).exists()
            status = "OK" if exists else "FALTA"
            print(f"  [{status}] {doc:<40} - {desc}")
            if not exists:
                all_ok = False

        self.report['checks']['documentation'] = all_ok
        return all_ok

    def generate_overall_status(self):
        """Gerar status geral"""
        print("\n" + "="*70)
        print("RESUMO DE SAÚDE DO SISTEMA")
        print("="*70 + "\n")

        checks_ok = sum(1 for v in self.report['checks'].values() if v is True or (isinstance(v, dict) and v.get('ok')))
        total_checks = len(self.report['checks'])

        print(f"Status geral: {checks_ok}/{total_checks} verificações OK\n")

        if self.report['errors']:
            print("ERROS ENCONTRADOS:")
            for error in self.report['errors']:
                print(f"  [ERRO] {error}")
            print()

        if self.report['warnings']:
            print("AVISOS:")
            for warning in self.report['warnings']:
                print(f"  [AVISO] {warning}")
            print()

        # Determinar status final
        if self.report['errors']:
            self.report['status'] = 'CRITICAL'
            status_emoji = "[CRITICAL]"
        elif self.report['warnings']:
            self.report['status'] = 'WARNING'
            status_emoji = "[WARNING]"
        else:
            self.report['status'] = 'HEALTHY'
            status_emoji = "[HEALTHY]"

        print(f"{status_emoji} STATUS FINAL: {self.report['status']}")
        print()

        return self.report['status']

    def save_report(self):
        """Salvar relatório de saúde"""
        report_file = Path('logs/system-health-check.json')
        report_file.parent.mkdir(parents=True, exist_ok=True)

        with open(report_file, 'w') as f:
            json.dump(self.report, f, indent=2)

        print(f"Relatório salvo em: {report_file}")

    def run_all_checks(self):
        """Executar todas as verificações"""
        print("\n" + "="*70)
        print("VERIFICAÇÃO DE SAÚDE DO SISTEMA - SHOPVIVALIZ")
        print("="*70)

        self.check_files_exist()
        self.check_queue_status()
        self.check_logs_exist()
        self.check_api_responses()
        self.check_workflows()
        self.check_config_files()
        self.check_agent_status()
        self.check_documentation()

        status = self.generate_overall_status()
        self.save_report()

        return status

if __name__ == "__main__":
    checker = SystemHealthCheck()
    final_status = checker.run_all_checks()

    # Retornar código de saída apropriado
    exit(0 if final_status == 'HEALTHY' else 1)
