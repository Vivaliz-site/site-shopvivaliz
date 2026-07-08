#!/usr/bin/env python3
"""Auto-sync simples: busca origin/main e faz hard reset se houver
mudanca. Roda a cada 30min via cron. Nunca deixa o working tree sujo
ou preso em rebase -- sempre reset --hard, sem merge/rebase local."""
import subprocess
import logging
import sys
from pathlib import Path

REPO_DIR = Path(__file__).resolve().parent
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(message)s',
)
log = logging.getLogger(__name__)


def run(cmd):
    return subprocess.run(
        cmd, cwd=REPO_DIR, capture_output=True, text=True, timeout=60
    )


def main():
    log.info('Git auto-sync iniciado')

    fetch = run(['git', 'fetch', 'origin', 'main'])
    if fetch.returncode != 0:
        log.error('Falha no fetch: %s', fetch.stderr.strip()[:300])
        sys.exit(1)

    local = run(['git', 'rev-parse', 'HEAD']).stdout.strip()
    remote = run(['git', 'rev-parse', 'origin/main']).stdout.strip()

    if local == remote:
        log.info('Ja atualizado (%s)', local[:8])
        return

    log.info('Atualizando %s -> %s', local[:8], remote[:8])
    reset = run(['git', 'reset', '--hard', 'origin/main'])
    if reset.returncode != 0:
        log.error('Falha no reset: %s', reset.stderr.strip()[:300])
        sys.exit(1)

    log.info('Sincronizado com sucesso para %s', remote[:8])


if __name__ == '__main__':
    main()
