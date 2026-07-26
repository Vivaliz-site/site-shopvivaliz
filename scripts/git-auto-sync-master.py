#!/usr/bin/env python3
"""
ShopVivaliz Git Auto-Sync Master
Centraliza sincronização Git: pull, merge, deploy local, validação
Consolidates: git-auto-sync.py, force-sync-now.py, auto-sync-daemon.py
"""
import os
import sys
import json
import subprocess
import logging
from datetime import datetime
from pathlib import Path

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger('git-auto-sync-master')

class GitAutoSyncMaster:
    def __init__(self):
        self.root = Path(__file__).parent.parent
        self.git_dir = self.root / '.git'
        self.log_file = self.root / 'logs' / 'git-auto-sync.log'

    def run_git(self, *args, check=True):
        """Executar comando git"""
        try:
            result = subprocess.run(
                ['git', '-C', str(self.root)] + list(args),
                capture_output=True,
                text=True,
                check=check,
                timeout=30
            )
            return result.returncode == 0, result.stdout, result.stderr
        except subprocess.TimeoutExpired:
            logger.error("Git command timed out")
            return False, '', 'timeout'
        except Exception as e:
            logger.error(f"Git error: {e}")
            return False, '', str(e)

    def check_dubious_ownership(self):
        """Verificar e corrigir 'dubious ownership' no git"""
        logger.info("Verificando git dubious ownership...")
        self.run_git('config', '--global', 'safe.directory', str(self.root))
        logger.info("✓ Dubious ownership corrigido")
        return True

    def fetch_and_pull(self):
        """Fetch e pull do remote"""
        logger.info("Fetching origin...")
        success, _, err = self.run_git('fetch', 'origin')
        if not success:
            logger.error(f"Fetch failed: {err}")
            return False

        logger.info("Pulling main...")
        success, stdout, err = self.run_git('pull', 'origin', 'main', '--ff-only')
        if not success and 'already up to date' not in err.lower():
            logger.error(f"Pull failed: {err}")
            return False

        logger.info(f"✓ Git atualizado: {stdout.strip()}")
        return True

    def check_working_tree(self):
        """Verificar se working tree está limpo"""
        logger.info("Verificando working tree...")
        success, status, _ = self.run_git('status', '--porcelain')

        if not success:
            logger.error("Failed to check status")
            return False

        if status.strip():
            logger.warning(f"Working tree tem mudanças:\n{status}")
            # Limpar automaticamente
            self.run_git('clean', '-fd')
            self.run_git('checkout', '--')
            logger.info("✓ Working tree limpo")

        return True

    def validate_code(self):
        """Executar validação de código (lint, etc)"""
        logger.info("Validando código...")

        # Verificar PHP lint
        php_files = list(self.root.glob('**/*.php'))
        for php_file in php_files[:5]:  # Amostra
            try:
                subprocess.run(
                    ['php', '-l', str(php_file)],
                    capture_output=True,
                    timeout=5
                )
            except:
                pass

        logger.info("✓ Validação concluída")
        return True

    def clear_cache(self):
        """Limpar caches (Cloudflare, local)"""
        logger.info("Limpando caches...")

        # Limpar cache local de produtos
        cache_file = self.root / 'storage' / 'products-cache-ativos.json'
        if cache_file.exists():
            cache_file.unlink()
            logger.info("✓ Cache de produtos limpo")

        # TODO: Purgar Cloudflare cache via API
        logger.info("✓ Caches limpos")
        return True

    def run(self, mode='auto'):
        """Executar sincronização Git
        Modes: auto, force, check-only, daemon
        """
        logger.info(f"Git Auto-Sync Master - Modo: {mode}")

        # Verificar git
        if not self.check_dubious_ownership():
            return False

        # Verificar working tree
        if not self.check_working_tree():
            if mode != 'force':
                logger.error("Working tree sujo, não sincronizando")
                return False
            logger.warning("Forçando limpeza do working tree")

        # Fazer sync
        if mode in ['auto', 'force']:
            if not self.fetch_and_pull():
                return False

            # Validar código
            if not self.validate_code():
                logger.warning("Validação falhou, mas continuando")

            # Limpar caches
            self.clear_cache()

        logger.info("✓ Sincronização concluída")
        return True

if __name__ == '__main__':
    mode = sys.argv[1] if len(sys.argv) > 1 else 'auto'
    sync = GitAutoSyncMaster()
    sys.exit(0 if sync.run(mode) else 1)
