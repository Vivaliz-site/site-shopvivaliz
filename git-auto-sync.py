#!/usr/bin/env python3
"""Auto-sync + Deploy: busca origin/main, faz hard reset, e sincroniza
com servidor via FTP. Roda a cada 30min via cron."""
import subprocess
import logging
import sys
import os
from pathlib import Path

REPO_DIR = Path(__file__).resolve().parent
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(message)s',
)
log = logging.getLogger(__name__)


def run(cmd, env=None):
    return subprocess.run(
        cmd, cwd=REPO_DIR, capture_output=True, text=True, timeout=60, env=env or os.environ.copy()
    )


def deploy_via_ftp():
    """Tenta sincronizar via FTP usando credentials de environment"""
    ftp_server = os.getenv('FTP_SERVER')
    ftp_user = os.getenv('FTP_USERNAME')
    ftp_pass = os.getenv('FTP_PASSWORD')
    ftp_port = os.getenv('FTP_PORT', '21')
    ftp_dir = os.getenv('FTP_REMOTE_DIR', '/public_html')

    if not all([ftp_server, ftp_user, ftp_pass]):
        log.warning('FTP credentials não encontrados, pulando deploy')
        return False

    # Tenta lftp
    lftp_cmd = f"""
    lftp -u {ftp_user},{ftp_pass} -p {ftp_port} {ftp_server} <<EOF
    set sftp:auto-confirm yes
    mirror -R --delete {REPO_DIR}/ {ftp_dir}/
    quit
EOF
    """

    log.info('Tentando deploy via LFTP...')
    result = run(['bash', '-c', lftp_cmd])
    if result.returncode == 0:
        log.info('Deploy via LFTP sucesso')
        return True
    else:
        log.warning('LFTP falhou: %s', result.stderr[:200])
        return False


def deploy_via_rsync():
    """Tenta sincronizar via rsync para diretório local"""
    try:
        # Tenta rsync para diretório padrão
        result = run(['rsync', '-avz', '--delete', f'{REPO_DIR}/', '/var/www/shopvivaliz/'])
        if result.returncode == 0:
            log.info('Deploy via rsync sucesso')
            return True
    except Exception as e:
        log.warning('Rsync indisponível: %s', str(e))
    return False


def main():
    log.info('Git auto-sync + Deploy iniciado')

    # 1. Fetch latest
    fetch = run(['git', 'fetch', 'origin', 'main'])
    if fetch.returncode != 0:
        log.error('Falha no fetch: %s', fetch.stderr.strip()[:300])
        sys.exit(1)

    local = run(['git', 'rev-parse', 'HEAD']).stdout.strip()
    remote = run(['git', 'rev-parse', 'origin/main']).stdout.strip()

    if local == remote:
        log.info('Ja atualizado (%s)', local[:8])
        return

    # 2. Reset para remote
    log.info('Atualizando %s -> %s', local[:8], remote[:8])
    reset = run(['git', 'reset', '--hard', 'origin/main'])
    if reset.returncode != 0:
        log.error('Falha no reset: %s', reset.stderr.strip()[:300])
        sys.exit(1)

    log.info('Repositório sincronizado: %s', remote[:8])

    # 3. Deploy para servidor
    log.info('Iniciando sincronização com servidor...')
    deployed = deploy_via_rsync() or deploy_via_ftp()

    if deployed:
        log.info('Deploy concluído com sucesso')
    else:
        log.warning('Nenhum método de deploy funcionou, archivos locais foram atualizados')


if __name__ == '__main__':
    main()
