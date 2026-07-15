#!/usr/bin/env python3
"""
Deploy Diagnostic - Diagnosticar erros de deployment
"""
import subprocess
import os
import json
from pathlib import Path
from urllib.parse import urlparse

class DeployDiagnostic:
    def __init__(self):
        self.issues = []
        self.warnings = []

    def check_ftp_credentials(self):
        """Verificar credenciais FTP"""
        print(" Verificando credenciais FTP...")

        ftp_host = os.getenv('FTP_SERVER')
        ftp_user = os.getenv('FTP_USERNAME')
        ftp_pass = os.getenv('FTP_PASSWORD')
        ftp_dir = os.getenv('FTP_REMOTE_DIR')

        if ftp_host and "://" in ftp_host:
            parsed = urlparse(ftp_host)
            if parsed.hostname:
                self.warnings.append(f" FTP_SERVER contem protocolo; prefira apenas: {parsed.hostname}")
            else:
                self.issues.append(" FTP_SERVER invalido: use apenas o host FTP")

        if not ftp_host:
            self.issues.append(" FTP_SERVER não configurado nos secrets")
        if not ftp_user:
            self.issues.append(" FTP_USERNAME não configurado")
        if not ftp_pass:
            self.issues.append(" FTP_PASSWORD não configurado")
        if not ftp_dir:
            self.warnings.append(" FTP_REMOTE_DIR não definido (verificar padrão)")

        if not self.issues:
            print("   Credenciais FTP configuradas")

    def check_file_permissions(self):
        """Verificar permissões de arquivos"""
        print(" Verificando permissões de arquivos...")

        files_to_check = [
            "admin/monitor/index.html",
            "api/monitor/api.php",
            "scripts/continuous-executor.py",
            ".github/workflows/deploy.yml",
            "scripts/deploy-validator.py",
            "config/secrets-groups.json",
            "docs/secrets-inventory.md",
        ]

        for file in files_to_check:
            if not Path(file).exists():
                self.issues.append(f" Arquivo não encontrado: {file}")
            else:
                print(f"   {file}")

    def check_github_secrets(self):
        """Verificar secrets do GitHub"""
        print(" Verificando GitHub Secrets...")

        required_secrets = [
            'FTP_SERVER',
            'FTP_USERNAME',
            'FTP_PASSWORD',
            'FTP_REMOTE_DIR',
            'ANTHROPIC_API_KEY',
            'OPENAI_API_KEY',
            'GEMINI_API_KEY',
            'EMAIL_USER',
            'EMAIL_PASSWORD'
        ]

        missing = [s for s in required_secrets if not os.getenv(s)]
        if os.getenv('SMTP_USER') and os.getenv('SMTP_PASS'):
            missing = [s for s in missing if s not in ('EMAIL_USER', 'EMAIL_PASSWORD')]

        if missing:
            print(f"   Secrets faltando: {', '.join(missing)}")
            self.warnings.append(f"Secrets não disponíveis localmente (OK em GitHub)")
        else:
            print("   Todos os secrets presentes")

    def check_workflow_syntax(self):
        """Verificar sintaxe dos workflows YAML"""
        print(" Verificando workflow YAML...")

        workflows = Path(".github/workflows").glob("*.yml")

        for wf in workflows:
            try:
                # Verificar se é YAML válido
                with open(wf, encoding='utf-8', errors='replace') as f:
                    content = f.read()
                    if "on:" in content and "jobs:" in content:
                        print(f"   {wf.name}")
                    else:
                        self.warnings.append(f" {wf.name} pode ter sintaxe incorreta")
            except Exception as e:
                self.issues.append(f" Erro ao ler {wf.name}: {e}")

    def check_php_syntax(self):
        """Verificar sintaxe PHP"""
        print(" Verificando PHP...")

        php_files = list(Path(".").rglob("*.php"))[:5]

        for php_file in php_files:
            try:
                result = subprocess.run(
                    ["php", "-l", str(php_file)],
                    capture_output=True,
                    text=True,
                    timeout=5
                )
                if result.returncode == 0:
                    print(f"   {php_file}")
            except Exception as e:
                self.warnings.append(f" PHP check falhou: {e}")
                break

    def check_git_status(self):
        """Verificar status do Git"""
        print(" Verificando Git...")

        result = subprocess.run(
            ["git", "status", "--porcelain"],
            capture_output=True,
            text=True
        )

        if result.stdout.strip():
            self.warnings.append(" Há arquivos não commitados")
        else:
            print("   Repositório limpo")

    def generate_report(self):
        """Gerar relatório de diagnóstico"""
        print("\n" + "=" * 60)
        print("RELATÓRIO DE DIAGNÓSTICO DE DEPLOY")
        print("=" * 60 + "\n")

        if self.issues:
            print(" PROBLEMAS ENCONTRADOS:\n")
            for issue in self.issues:
                print(f"  {issue}")
            print()

        if self.warnings:
            print(" AVISOS:\n")
            for warning in self.warnings:
                print(f"  {warning}")
            print()

        if not self.issues and not self.warnings:
            print(" NENHUM PROBLEMA DETECTADO\n")

        print("=" * 60)

        Path("logs").mkdir(exist_ok=True)
        Path("logs/deploy-diagnostic.json").write_text(
            json.dumps({
                "ok": len(self.issues) == 0,
                "issues": self.issues,
                "warnings": self.warnings,
            }, indent=2, ensure_ascii=False) + "\n",
            encoding="utf-8",
        )

        return len(self.issues) == 0

    def run_all_checks(self):
        """Rodar todos os checks"""
        print("\n" + "=" * 60)
        print(" DEPLOY DIAGNOSTIC")
        print("=" * 60 + "\n")

        self.check_ftp_credentials()
        self.check_file_permissions()
        self.check_github_secrets()
        self.check_workflow_syntax()
        self.check_php_syntax()
        self.check_git_status()

        success = self.generate_report()

        if not success:
            print("\nSOLUCOES:\n")
            print("1. Verifique os secrets no GitHub:")
            print("   https://github.com/seu-repo/settings/secrets/actions\n")
            print("2. Certifique-se de que FTP_REMOTE_DIR = /\n")
            print("3. Verifique os logs no GitHub Actions\n")

        return success

if __name__ == "__main__":
    diagnostic = DeployDiagnostic()
    success = diagnostic.run_all_checks()

    exit(0 if success else 1)
