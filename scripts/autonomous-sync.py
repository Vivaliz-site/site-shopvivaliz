#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Compatibilidade para a sincronização autônoma.

O runner oficial agora é o JS tri-environment-sync.js, que mantém PC,
nuvem/GitHub e Oracle sincronizados sem push direto para main.
"""
import os
import sys
import json
import subprocess
import shutil
import hashlib
from pathlib import Path
from datetime import datetime
import logging

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler('logs/autonomous-sync.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

JS_SYNC_SCRIPT = Path("scripts/tri-environment-sync.js")

def run_js_sync():
    """Executar o runner JS oficial quando disponível."""
    if not JS_SYNC_SCRIPT.exists():
        return False

    node = shutil.which("node") or shutil.which("node.exe")
    if not node:
        return False

    try:
        result = subprocess.run(
            [node, str(JS_SYNC_SCRIPT)],
            capture_output=True,
            text=True,
            cwd=CURRENT_DIR
        )

        if result.stdout:
            print(result.stdout)
        if result.stderr:
            logger.warning(result.stderr.strip())

        if result.returncode == 0:
            logger.info("JS sync runner completed successfully")
        elif result.returncode == 1:
            logger.warning("JS sync runner completed with warning status")
        else:
            logger.error(f"JS sync runner completed with critical status code {result.returncode}")

        return True
    except Exception as e:
        logger.error(f"JS sync runner error: {e}")
        return False

print("="*70)
print("SINCRONIZACAO AUTONOMA BIDIRECIONAL v1.0")
print("="*70)

# Paths
CURRENT_DIR = Path.cwd()
FRED_PATH = Path("C:/FRED/site-shopvivaliz") if Path("C:/FRED/site-shopvivaliz").exists() else None
USER_PATH = Path("c:/user/site-shopvivaliz")  # Current directory

if not USER_PATH.exists():
    USER_PATH = CURRENT_DIR

IGNORE_PATTERNS = [
    '.git',
    '__pycache__',
    '.venv',
    'node_modules',
    '.env',
    '.env.local',
    '*.log',
    'logs/',
    '.DS_Store',
    'Thumbs.db'
]

def should_ignore(path_str):
    """Check if path matches ignore patterns"""
    for pattern in IGNORE_PATTERNS:
        if pattern in path_str:
            return True
    return False

def get_file_hash(file_path):
    """Get SHA256 hash of file"""
    try:
        sha256 = hashlib.sha256()
        with open(file_path, 'rb') as f:
            for byte_block in iter(lambda: f.read(4096), b""):
                sha256.update(byte_block)
        return sha256.hexdigest()
    except Exception as e:
        logger.error(f"Erro ao hash {file_path}: {e}")
        return None

def sync_directories(src, dst, direction):
    """Sync files from src to dst"""
    copied = 0
    skipped = 0

    try:
        if not src.exists():
            logger.warning(f"Source dir not found: {src}")
            return copied, skipped

        os.makedirs(dst, exist_ok=True)

        for src_file in src.rglob('*'):
            if should_ignore(str(src_file)):
                continue

            if src_file.is_dir():
                dst_dir = dst / src_file.relative_to(src)
                os.makedirs(dst_dir, exist_ok=True)
                continue

            dst_file = dst / src_file.relative_to(src)

            # Skip if destination is newer
            try:
                if dst_file.exists():
                    src_mtime = src_file.stat().st_mtime
                    dst_mtime = dst_file.stat().st_mtime

                    # If files are identical, skip
                    if abs(src_mtime - dst_mtime) < 1:  # Within 1 second
                        skipped += 1
                        continue
            except OSError:
                pass

            # Copy file
            try:
                os.makedirs(dst_file.parent, exist_ok=True)
                shutil.copy2(src_file, dst_file)
                logger.info(f"Sync {direction}: {src_file.relative_to(src)}")
                copied += 1
            except Exception as e:
                logger.error(f"Erro copying {src_file}: {e}")
                skipped += 1

    except Exception as e:
        logger.error(f"Sync error {src} -> {dst}: {e}")

    return copied, skipped

def git_auto_pull():
    """Auto-pull from remote with conflict resolution"""
    logger.info("Iniciando git pull...")
    try:
        # Fetch remote
        result = subprocess.run(
            ['git', 'fetch', 'origin', 'main'],
            capture_output=True,
            text=True,
            cwd=USER_PATH
        )

        if result.returncode != 0:
            logger.warning(f"Git fetch failed: {result.stderr}")
            return False

        # Try to merge
        result = subprocess.run(
            ['git', 'merge', '-X', 'ours', 'origin/main'],
            capture_output=True,
            text=True,
            cwd=USER_PATH
        )

        if result.returncode == 0:
            logger.info("Git pull successful")
            return True
        else:
            logger.warning(f"Git merge issue: {result.stderr}")
            # Try to resolve with ours strategy
            subprocess.run(['git', 'merge', '--abort'], capture_output=True, cwd=USER_PATH)
            return False

    except Exception as e:
        logger.error(f"Git pull error: {e}")
        return False

def detect_changes():
    """Detect local changes"""
    try:
        result = subprocess.run(
            ['git', 'status', '--porcelain'],
            capture_output=True,
            text=True,
            cwd=USER_PATH
        )

        changes = result.stdout.strip().split('\n')
        changes = [c for c in changes if c and not should_ignore(c)]
        return changes
    except Exception as e:
        logger.error(f"Error detecting changes: {e}")
        return []

def auto_commit_changes(changes):
    """Auto-commit local changes"""
    if not changes:
        logger.info("No changes to commit")
        return False

    try:
        # Stage changes
        subprocess.run(['git', 'add', '-A'], cwd=USER_PATH)

        # Create commit message
        timestamp = datetime.now().isoformat()
        msg = f"chore: auto-sync mudancas locais [{timestamp}]\n\n"
        msg += f"Files changed: {len(changes)}\n"
        msg += "Merged from C:\\FRED environment\n"
        msg += "[AUTO-SYNC]"

        result = subprocess.run(
            ['git', 'commit', '-m', msg],
            capture_output=True,
            text=True,
            cwd=USER_PATH
        )

        if result.returncode == 0:
            logger.info(f"Committed {len(changes)} changes")
            return True
        else:
            logger.warning(f"Commit failed: {result.stderr}")
            return False

    except Exception as e:
        logger.error(f"Commit error: {e}")
        return False

def main():
    if run_js_sync():
        return

    sync_report = {
        'timestamp': datetime.now().isoformat(),
        'status': 'completed',
        'operations': []
    }

    try:
        # Step 1: Git pull (fetch remote changes)
        logger.info("=" * 70)
        logger.info("STEP 1: Git Pull (fetch remote changes)")
        logger.info("=" * 70)
        git_auto_pull()

        # Step 2: Sync C:\FRED -> c:/user (if FRED exists)
        if FRED_PATH and FRED_PATH.exists():
            logger.info("=" * 70)
            logger.info(f"STEP 2: Sync {FRED_PATH} -> {USER_PATH}")
            logger.info("=" * 70)
            copied, skipped = sync_directories(FRED_PATH, USER_PATH, "FRED->USER")
            sync_report['operations'].append({
                'type': 'sync',
                'direction': 'FRED->USER',
                'files_copied': copied,
                'files_skipped': skipped
            })
            logger.info(f"Resultado: {copied} copiados, {skipped} ignorados")

        # Step 3: Detect changes and commit
        logger.info("=" * 70)
        logger.info("STEP 3: Detect Changes and Commit")
        logger.info("=" * 70)
        changes = detect_changes()

        if changes:
            logger.info(f"Detected {len(changes)} changes")
            if auto_commit_changes(changes):
                sync_report['status'] = 'committed'
                sync_report['changes_count'] = len(changes)
            else:
                sync_report['status'] = 'partial'
        else:
            logger.info("No changes detected")
            sync_report['status'] = 'no_changes'

        # Step 4: Sync c:/user -> C:\FRED (if FRED exists)
        if FRED_PATH and FRED_PATH.exists():
            logger.info("=" * 70)
            logger.info(f"STEP 4: Sync {USER_PATH} -> {FRED_PATH} (reverse)")
            logger.info("=" * 70)
            copied, skipped = sync_directories(USER_PATH, FRED_PATH, "USER->FRED")
            sync_report['operations'].append({
                'type': 'sync',
                'direction': 'USER->FRED',
                'files_copied': copied,
                'files_skipped': skipped
            })
            logger.info(f"Resultado: {copied} copiados, {skipped} ignorados")

        sync_report['status'] = 'completed'

    except Exception as e:
        logger.error(f"Sync error: {e}", exc_info=True)
        sync_report['status'] = 'error'
        sync_report['error'] = str(e)

    # Save report
    finally:
        os.makedirs('logs', exist_ok=True)
        with open('logs/autonomous-sync.json', 'w') as f:
            json.dump(sync_report, f, indent=2)

        logger.info("=" * 70)
        logger.info(f"SYNC COMPLETED - Status: {sync_report['status']}")
        logger.info("=" * 70)

if __name__ == '__main__':
    main()
