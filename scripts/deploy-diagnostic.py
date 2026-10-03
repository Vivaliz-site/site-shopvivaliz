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

    def check_public_storage(self):
        """Verificar storage público persistente da VM de produção."""
        print(" Verificando public storage...")
        root = Path(os.getenv('SHOPVIVALIZ_PUBLIC_ROOT') or Path.cwd())
        uploads = root / 'uploads'
        try:
            uploads.mkdir(parents=True, exist_ok=True)
            probe = uploads / '.deploy-diagnostic-write-probe'
            probe.write_text('ok', encoding='utf-8')
            probe.unlink()
            print(f"   Public storage gravável: {uploads}")
        except Exception as exc:
            self.issues.append(f" Public storage indisponível: {exc}")

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
            'ANTHROPIC_API_KEY',
            'OPENAI_API_KEY',
            'GEMINI_API_KEY',
            'BREVO_API_KEY',
            'EMAIL_TO'
        ]

        missing = [s for s in required_secrets if not os.getenv(s)]

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

        self.check_public_storage()
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
