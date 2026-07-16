#!/usr/bin/env python3
"""
Script para migrar automaticamente TODOS os scripts Python
para usar o módulo centralizado de secrets (config.secrets).

Uso:
    python3 scripts/migrar_secrets.py --scan          # Escanear scripts
    python3 scripts/migrar_secrets.py --dry-run      # Simular migração
    python3 scripts/migrar_secrets.py --migrate      # Executar migração
    python3 scripts/migrar_secrets.py --revert       # Reverter backups
"""

import argparse
import re
import sys
from pathlib import Path
from typing import Dict, List, Tuple, Set
import json
from datetime import datetime

# Mapping de variáveis de ambiente para imports
SECRETS_MAPPING = {
    "GEMINI_API_KEY": "GEMINI_API_KEY",
    "ANTHROPIC_API_KEY": "ANTHROPIC_API_KEY",
    "OPENAI_API_KEY": "OPENAI_API_KEY",

    # Shopee
    "SHOPEE_PARTNER_ID": "SHOPEE_PARTNER_ID",
    "SHOPEE_PARTNER_KEY": "SHOPEE_PARTNER_KEY",
    "SHOPEE_SHOP_ID": "SHOPEE_SHOP_ID",
    "SHOPEE_ACCESS_TOKEN": "SHOPEE_ACCESS_TOKEN",
    "SHOPEE_REFRESH_TOKEN": "SHOPEE_REFRESH_TOKEN",
    "SHOPEE_API_BASE_URL": "SHOPEE_API_BASE_URL",
    "SHOPEE_REDIRECT_URI": "SHOPEE_REDIRECT_URI",

    # Amazon
    "AMAZON_LWA_CLIENT_ID": "AMAZON_LWA_CLIENT_ID",
    "AMAZON_LWA_CLIENT_SECRET": "AMAZON_LWA_CLIENT_SECRET",
    "AMAZON_LWA_ACCESS_TOKEN": "AMAZON_LWA_ACCESS_TOKEN",
    "AMAZON_AWS_ACCESS_KEY_ID": "AMAZON_AWS_ACCESS_KEY_ID",
    "AMAZON_AWS_SECRET_ACCESS_KEY": "AMAZON_AWS_SECRET_ACCESS_KEY",

    # Olist
    "OLIST_API_KEY": "OLIST_API_KEY",
    "OLIST_CLIENT_ID": "OLIST_CLIENT_ID",
    "OLIST_CLIENT_SECRET": "OLIST_CLIENT_SECRET",
    "OLIST_ACCESS_TOKEN": "OLIST_ACCESS_TOKEN",
    "OLIST_REDIRECT_URI": "OLIST_REDIRECT_URI",

    # FTP
    "FTP_SERVER": "FTP_SERVER",
    "FTP_HOST": "FTP_HOST",
    "FTP_USERNAME": "FTP_USERNAME",
    "FTP_PASSWORD": "FTP_PASSWORD",
    "FTP_PORT": "FTP_PORT",
    "FTP_REMOTE_DIR": "FTP_REMOTE_DIR",

    # Email
    "MAIL_HOST": "MAIL_HOST",
    "MAIL_PORT": "MAIL_PORT",
    "MAIL_USER": "MAIL_USER",
    "MAIL_PASS": "MAIL_PASS",
    "SMTP_HOST": "SMTP_HOST",
    "SMTP_PORT": "SMTP_PORT",
    "SMTP_USER": "SMTP_USER",
    "SMTP_PASS": "SMTP_PASS",
    "EMAIL_FROM": "EMAIL_FROM",
    "EMAIL_TO": "EMAIL_TO",

    # Payment
    "PAGARME_SECRET_KEY": "PAGARME_SECRET_KEY",
    "PAGARME_API_KEY": "PAGARME_API_KEY",

    # Shipping
    "MELHORENVIO_ACCESS_TOKEN": "MELHORENVIO_ACCESS_TOKEN",

    # Security
    "SESSION_SECRET": "SESSION_SECRET",
    "JWT_SECRET": "JWT_SECRET",

    # App
    "APP_ENV": "APP_ENV",
    "APP_DEBUG": "APP_DEBUG",
    "APP_URL": "APP_URL",
}

class SecretsMigrator:
    def __init__(self, scripts_dir: Path = None):
        self.scripts_dir = scripts_dir or Path("scripts")
        self.backup_dir = Path(".backups/secrets_migration")
        self.backup_dir.mkdir(parents=True, exist_ok=True)
        self.migration_log = []

    def find_scripts(self) -> List[Path]:
        """Encontra todos os arquivos Python no projeto."""
        scripts = []
        for pattern in ["scripts/**/*.py", "**/*.py"]:
            for py_file in Path(".").glob(pattern):
                # Ignorar venv, node_modules, .git, etc
                if any(x in py_file.parts for x in [".venv", "venv", "node_modules", ".git", "vendor", ".tmp"]):
                    continue
                if py_file.is_file():
                    scripts.append(py_file)
        return sorted(set(scripts))

    def find_getenv_calls(self, content: str) -> Set[str]:
        """Encontra todas as chamadas de os.getenv() no arquivo."""
        pattern = r'os\.getenv\(\s*["\']([A-Z_]+)["\']'
        matches = re.findall(pattern, content)
        return set(m for m in matches if m in SECRETS_MAPPING)

    def find_load_dotenv_calls(self, content: str) -> List[str]:
        """Encontra chamadas de load_dotenv()."""
        return re.findall(r'load_dotenv\([^)]*\)', content)

    def analyze_script(self, script_path: Path) -> Dict:
        """Analisa um script e encontra o que precisa ser migrado."""
        try:
            content = script_path.read_text(encoding='utf-8')
        except Exception as e:
            return {"error": str(e)}

        secrets_used = self.find_getenv_calls(content)
        has_load_dotenv = bool(self.find_load_dotenv_calls(content))
        has_os_import = "import os" in content
        has_from_os_import = "from os import" in content
        has_dotenv_import = "from dotenv import" in content or "import dotenv" in content
        has_config_import = "from config.secrets import" in content or "from config import" in content

        return {
            "path": str(script_path),
            "needs_migration": bool(secrets_used or has_load_dotenv),
            "secrets_used": sorted(secrets_used),
            "has_os_import": has_os_import,
            "has_from_os_import": has_from_os_import,
            "has_dotenv_import": has_dotenv_import,
            "has_config_import": has_config_import,
            "lines_count": len(content.splitlines()),
        }

    def migrate_script(self, script_path: Path, dry_run: bool = True) -> Tuple[bool, str]:
        """Migra um script para usar config.secrets."""
        try:
            content = script_path.read_text(encoding='utf-8')
        except Exception as e:
            return False, f"Erro ao ler: {e}"

        # Se já foi migrado, pular
        if "from config.secrets import" in content:
            return False, "Já migrado"

        secrets_used = self.find_getenv_calls(content)
        if not secrets_used:
            return False, "Nenhum secret encontrado"

        # Backup
        backup_path = self.backup_dir / f"{script_path.name}.{datetime.now().strftime('%Y%m%d_%H%M%S')}.bak"
        if not dry_run:
            backup_path.write_text(content, encoding='utf-8')

        # Remover load_dotenv
        migrated = re.sub(
            r'(?:from\s+dotenv\s+import\s+load_dotenv|import\s+dotenv).*\n',
            '',
            content
        )
        migrated = re.sub(r'load_dotenv\([^)]*\)\n', '', migrated)

        # Adicionar import centralizado (no topo após imports)
        import_stmt = f"from config.secrets import (\n"
        for secret in sorted(secrets_used):
            import_stmt += f"    {secret},\n"
        import_stmt += ")\n"

        # Encontrar linha após último import
        lines = migrated.split('\n')
        last_import_idx = 0
        for i, line in enumerate(lines):
            if line.startswith('import ') or line.startswith('from '):
                last_import_idx = i

        # Inserir import
        lines.insert(last_import_idx + 1, import_stmt)
        migrated = '\n'.join(lines)

        # Substituir os.getenv() por variáveis diretas
        for secret in secrets_used:
            pattern = rf'os\.getenv\(\s*["\'{secret}["\'](?:\s*,\s*["\']?[^"\'\)]*["\']?)?\s*\)'
            migrated = re.sub(pattern, secret, migrated)

        if not dry_run:
            script_path.write_text(migrated, encoding='utf-8')
            return True, f"Migrado com sucesso (backup: {backup_path.name})"
        else:
            return True, f"Pronto para migrar ({len(secrets_used)} secrets)"

    def scan(self) -> None:
        """Escaneia todos os scripts."""
        scripts = self.find_scripts()
        print(f"\n🔍 Escaneando {len(scripts)} arquivos Python...\n")

        needs_migration = []
        already_migrated = []
        no_secrets = []

        for script in scripts:
            analysis = self.analyze_script(script)

            if analysis.get("error"):
                continue

            if analysis["has_config_import"]:
                already_migrated.append(script)
            elif analysis["needs_migration"]:
                needs_migration.append((script, analysis["secrets_used"]))
            else:
                no_secrets.append(script)

        print(f"✅ Já migrados: {len(already_migrated)}")
        for script in already_migrated[:5]:
            print(f"   ✓ {script}")
        if len(already_migrated) > 5:
            print(f"   ... e mais {len(already_migrated) - 5}")

        print(f"\n⚠️  Precisam migração: {len(needs_migration)}")
        for script, secrets in needs_migration[:10]:
            print(f"   → {script}")
            print(f"      Secrets: {', '.join(secrets[:3])}{'...' if len(secrets) > 3 else ''}")
        if len(needs_migration) > 10:
            print(f"   ... e mais {len(needs_migration) - 10}")

        print(f"\n⭕ Sem secrets: {len(no_secrets)}")

    def dry_run(self) -> None:
        """Simula a migração sem fazer mudanças."""
        scripts = self.find_scripts()
        print(f"\n🎬 DRY RUN: Simulando migração de {len(scripts)} scripts...\n")

        migrated_count = 0
        for script in scripts:
            success, msg = self.migrate_script(script, dry_run=True)
            if success:
                migrated_count += 1
                print(f"✓ {script.name}: {msg}")

        print(f"\n📊 Total: {migrated_count} scripts prontos para migração")

    def migrate(self) -> None:
        """Executa a migração de verdade."""
        scripts = self.find_scripts()
        print(f"\n🚀 Iniciando migração de {len(scripts)} scripts...\n")

        migrated_count = 0
        skipped_count = 0

        for script in scripts:
            success, msg = self.migrate_script(script, dry_run=False)
            if success:
                migrated_count += 1
                print(f"✅ {script.name}")
            else:
                skipped_count += 1
                if skipped_count <= 5:
                    print(f"⏭️  {script.name}: {msg}")

        print(f"\n✅ Migrados: {migrated_count}")
        print(f"⏭️  Pulados: {skipped_count}")
        print(f"📁 Backups salvos em: {self.backup_dir}")

    def revert(self) -> None:
        """Reverte a migração a partir dos backups."""
        backups = list(self.backup_dir.glob("*.bak"))
        if not backups:
            print("❌ Nenhum backup encontrado para reverter")
            return

        print(f"\n⏮️  Revertendo {len(backups)} backups...\n")
        for backup in backups:
            original_name = backup.name.split('.')[0]
            original_path = Path("scripts") / original_name
            content = backup.read_text(encoding='utf-8')
            original_path.write_text(content, encoding='utf-8')
            print(f"✓ Restaurado: {original_path}")

        print(f"\n✅ Revertido com sucesso!")

def main():
    parser = argparse.ArgumentParser(
        description="Migrar scripts para usar secrets centralizados"
    )
    parser.add_argument(
        "action",
        choices=["scan", "dry-run", "migrate", "revert"],
        help="Ação a executar"
    )
    parser.add_argument(
        "--scripts-dir",
        type=Path,
        default=Path("scripts"),
        help="Diretório de scripts"
    )

    args = parser.parse_args()
    migrator = SecretsMigrator(args.scripts_dir)

    if args.action == "scan":
        migrator.scan()
    elif args.action == "dry-run":
        migrator.dry_run()
    elif args.action == "migrate":
        migrator.migrate()
    elif args.action == "revert":
        migrator.revert()

if __name__ == "__main__":
    main()
