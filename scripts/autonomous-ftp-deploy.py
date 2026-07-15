#!/usr/bin/env python3
"""
Auto-Deploy FTP - Faz deploy automático quando mudanças são detectadas
Executa após git push bem-sucedido
"""
import os
import sys
import json
import subprocess
import ftplib
from pathlib import Path
from datetime import datetime, timedelta
import logging

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler('logs/autonomous-ftp-deploy.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

print("="*70)
print("AUTO-DEPLOY FTP v1.0 - Deploy automático ao detectar push")
print("="*70)

# Load FTP config from secrets/env
FTP_HOST = os.getenv('FTP_HOST', 'ftp.shopvivaliz.com.br')
FTP_USER = os.getenv('FTP_USER', '')
FTP_PASS = os.getenv('FTP_PASS', '')
FTP_REMOTE_PATH = os.getenv('FTP_REMOTE_PATH', '/public_html/')
FTP_PORT = int(os.getenv('FTP_PORT', '21'))

# Files to ignore during deploy
IGNORE_PATTERNS = [
    '.git',
    '.gitignore',
    '__pycache__',
    '.venv',
    'venv',
    'node_modules',
    '.env',
    '.env.local',
    '.env.*.local',
    '*.log',
    'logs/',
    '.DS_Store',
    'Thumbs.db',
    '.github/',
    'config/',
    'scripts/',
    '*.md',
    'CNAME',
    '.htaccess.bak'
]

def should_deploy(file_path):
    """Check if file should be deployed"""
    file_str = str(file_path).lower()
    for pattern in IGNORE_PATTERNS:
        if pattern in file_str:
            return False
    return True

def should_skip_for_ftp(file_path):
    """Skip certain files from FTP deploy"""
    extensions = ['.json', '.py', '.ps1', '.sh']
    for ext in extensions:
        if file_path.endswith(ext):
            return True
    return False

def check_last_push():
    """Check if there was a successful push in the last 15 minutes"""
    try:
        log_file = Path('logs/autonomous-git-push.json')
        if not log_file.exists():
            logger.warning("No push log found")
            return False

        with open(log_file, 'r') as f:
            data = json.load(f)

        timestamp_str = data.get('timestamp', '')
        if not timestamp_str:
            return False

        # Parse timestamp
        try:
            last_push = datetime.fromisoformat(timestamp_str)
        except:
            return False

        # Check if within 15 minutes
        time_diff = datetime.now() - last_push
        if time_diff <= timedelta(minutes=15):
            if data.get('status') == 'pushed':
                logger.info(f"Last push was {time_diff.total_seconds():.0f}s ago")
                return True

        return False

    except Exception as e:
        logger.error(f"Error checking last push: {e}")
        return False

def get_changed_files():
    """Get list of changed files from last commit"""
    try:
        result = subprocess.run(
            ['git', 'diff', '--name-only', 'HEAD~1..HEAD'],
            capture_output=True,
            text=True,
            timeout=10
        )

        if result.returncode == 0:
            files = result.stdout.strip().split('\n')
            return [f for f in files if f and should_deploy(f)]
        return []

    except Exception as e:
        logger.error(f"Error getting changed files: {e}")
        return []

def upload_to_ftp(local_file, remote_file, ftp_conn):
    """Upload single file to FTP"""
    try:
        # Ensure remote directory exists
        remote_dir = Path(remote_file).parent
        try:
            # Try to create directory
            ftp_conn.mkd(str(remote_dir))
        except ftplib.error_perm:
            # Directory might already exist
            pass

        # Upload file
        with open(local_file, 'rb') as f:
            ftp_conn.storbinary(f'STOR {remote_file}', f)

        logger.info(f"Uploaded: {remote_file}")
        return True

    except Exception as e:
        logger.error(f"Error uploading {remote_file}: {e}")
        return False

def deploy_to_ftp(files):
    """Deploy changed files to FTP"""
    if not files:
        logger.warning("No files to deploy")
        return False, 0

    # Check credentials
    if not FTP_HOST or not FTP_USER or not FTP_PASS:
        logger.error("FTP credentials not configured")
        logger.error("Set FTP_HOST, FTP_USER, FTP_PASS environment variables")
        return False, 0

    try:
        logger.info(f"Connecting to FTP: {FTP_HOST}...")
        ftp = ftplib.FTP(FTP_HOST, FTP_USER, FTP_PASS, timeout=30)
        logger.info("Connected to FTP")

        # Change to remote directory
        try:
            ftp.cwd(FTP_REMOTE_PATH)
            logger.info(f"Changed to remote directory: {FTP_REMOTE_PATH}")
        except ftplib.error_perm:
            logger.warning(f"Cannot change to {FTP_REMOTE_PATH}, using root")

        uploaded = 0
        failed = 0

        for file_path in files:
            if should_skip_for_ftp(file_path):
                logger.info(f"Skipped (FTP): {file_path}")
                continue

            local_file = Path(file_path)
            if not local_file.exists():
                logger.warning(f"File not found: {file_path}")
                failed += 1
                continue

            # Upload
            remote_file = file_path.replace('\\', '/')
            if upload_to_ftp(str(local_file), remote_file, ftp):
                uploaded += 1
            else:
                failed += 1

        ftp.quit()
        logger.info(f"FTP connection closed")

        return uploaded > 0, uploaded

    except ftplib.error_perm as e:
        logger.error(f"FTP permission error: {e}")
        return False, 0
    except ftplib.error_temp as e:
        logger.error(f"FTP temporary error: {e}")
        return False, 0
    except Exception as e:
        logger.error(f"FTP error: {e}", exc_info=True)
        return False, 0

def test_ftp_connection():
    """Test FTP connection"""
    try:
        logger.info("Testing FTP connection...")
        ftp = ftplib.FTP(FTP_HOST, FTP_USER, FTP_PASS, timeout=10)
        logger.info(f"FTP connected: {ftp.getwelcome()}")
        ftp.quit()
        return True
    except Exception as e:
        logger.error(f"FTP connection test failed: {e}")
        return False

def main():
    deploy_report = {
        'timestamp': datetime.now().isoformat(),
        'status': 'completed',
        'ftp_deployed': False,
        'files_uploaded': 0
    }

    try:
        logger.info("="*70)
        logger.info("STEP 1: Check if push occurred recently")
        logger.info("="*70)

        if not check_last_push():
            logger.info("No recent push detected - skipping deploy")
            deploy_report['status'] = 'no_push'
            return

        logger.info("="*70)
        logger.info("STEP 2: Get changed files")
        logger.info("="*70)

        changed_files = get_changed_files()
        if not changed_files:
            logger.info("No changed files to deploy")
            deploy_report['status'] = 'no_changes'
            return

        logger.info(f"Found {len(changed_files)} files to deploy")
        for f in changed_files[:10]:
            logger.info(f"  {f}")

        logger.info("="*70)
        logger.info("STEP 3: Test FTP connection")
        logger.info("="*70)

        if not test_ftp_connection():
            deploy_report['status'] = 'ftp_connection_failed'
            logger.error("Cannot proceed without FTP connection")
            return

        logger.info("="*70)
        logger.info("STEP 4: Deploy to FTP")
        logger.info("="*70)

        success, uploaded = deploy_to_ftp(changed_files)

        if success:
            deploy_report['status'] = 'deployed'
            deploy_report['ftp_deployed'] = True
            deploy_report['files_uploaded'] = uploaded
            logger.info(f"Deployed {uploaded} files successfully")
        else:
            deploy_report['status'] = 'deploy_failed'
            logger.error("Deploy failed")

    except Exception as e:
        logger.error(f"Error: {e}", exc_info=True)
        deploy_report['status'] = 'error'
        deploy_report['error'] = str(e)

    finally:
        os.makedirs('logs', exist_ok=True)
        with open('logs/autonomous-ftp-deploy.json', 'w') as f:
            json.dump(deploy_report, f, indent=2)

        logger.info("="*70)
        logger.info(f"DEPLOY STATUS: {deploy_report['status']}")
        logger.info(f"Files uploaded: {deploy_report['files_uploaded']}")
        logger.info("="*70)

if __name__ == '__main__':
    main()
