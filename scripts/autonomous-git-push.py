#!/usr/bin/env python3
"""
Auto-Push Automático - Detecta e faz push de mudanças
Executa a cada 10 minutos via GitHub Actions + Windows Task Scheduler
"""
import os
import sys
import json
import subprocess
from pathlib import Path
from datetime import datetime
import logging

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler('logs/autonomous-git-push.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

print("="*70)
print("AUTO-PUSH AUTOMATICO v1.0 - Detecta e faz push de mudancas")
print("="*70)

def get_git_status():
    """Get porcelain git status"""
    try:
        result = subprocess.run(
            ['git', 'status', '--porcelain'],
            capture_output=True,
            text=True
        )
        return result.stdout.strip().split('\n') if result.stdout.strip() else []
    except Exception as e:
        logger.error(f"Error getting git status: {e}")
        return []

def get_git_diff_stat():
    """Get summary of changes"""
    try:
        result = subprocess.run(
            ['git', 'diff', '--stat', 'HEAD'],
            capture_output=True,
            text=True
        )
        return result.stdout.strip()
    except Exception as e:
        logger.error(f"Error getting diff: {e}")
        return ""

def get_current_branch():
    """Get current branch name"""
    try:
        result = subprocess.run(
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'],
            capture_output=True,
            text=True
        )
        return result.stdout.strip()
    except Exception as e:
        logger.error(f"Error getting branch: {e}")
        return "unknown"

def stage_changes():
    """Stage all changes"""
    try:
        result = subprocess.run(['git', 'add', '-A'], capture_output=True, text=True)
        if result.returncode == 0:
            logger.info("Changes staged")
            return True
        else:
            logger.error(f"Staging error: {result.stderr}")
            return False
    except Exception as e:
        logger.error(f"Error staging: {e}")
        return False

def create_commit(changes_count, diff_stat):
    """Create commit with descriptive message"""
    try:
        timestamp = datetime.now().isoformat()
        branch = get_current_branch()

        msg = f"chore: auto-push mudancas detectadas [{datetime.now().strftime('%H:%M')}]\n\n"
        msg += f"Branch: {branch}\n"
        msg += f"Files changed: {changes_count}\n"
        msg += "Merged changes from local sync\n"
        msg += "[AUTO-PUSH]"

        result = subprocess.run(
            ['git', 'commit', '-m', msg],
            capture_output=True,
            text=True
        )

        if result.returncode == 0:
            logger.info(f"Commit created with {changes_count} changes")
            return True
        elif "nothing to commit" in result.stderr:
            logger.info("Nothing to commit (no changes)")
            return False
        else:
            logger.error(f"Commit error: {result.stderr}")
            return False

    except Exception as e:
        logger.error(f"Error creating commit: {e}")
        return False

def push_to_remote():
    """Push commits to remote"""
    try:
        branch = get_current_branch()
        result = subprocess.run(
            ['git', 'push', 'origin', branch],
            capture_output=True,
            text=True,
            timeout=30
        )

        if result.returncode == 0:
            logger.info(f"Pushed to origin/{branch}")
            return True
        else:
            logger.warning(f"Push error: {result.stderr}")
            # Try to pull and merge first
            logger.info("Attempting git pull before push...")
            pull_result = subprocess.run(
                ['git', 'pull', '--rebase', 'origin', branch],
                capture_output=True,
                text=True,
                timeout=30
            )
            if pull_result.returncode == 0:
                # Try push again
                result = subprocess.run(
                    ['git', 'push', 'origin', branch],
                    capture_output=True,
                    text=True,
                    timeout=30
                )
                if result.returncode == 0:
                    logger.info(f"Pushed to origin/{branch} (after rebase)")
                    return True
            logger.error(f"Push failed even after rebase: {result.stderr}")
            return False

    except subprocess.TimeoutExpired:
        logger.error("Push timeout (30s)")
        return False
    except Exception as e:
        logger.error(f"Error pushing: {e}")
        return False

def get_recent_changes():
    """Get list of recently changed files"""
    try:
        result = subprocess.run(
            ['git', 'diff', '--name-only', 'HEAD~1..HEAD'],
            capture_output=True,
            text=True
        )
        return result.stdout.strip().split('\n') if result.stdout.strip() else []
    except Exception as e:
        logger.error(f"Error getting recent changes: {e}")
        return []

def main():
    push_report = {
        'timestamp': datetime.now().isoformat(),
        'status': 'completed',
        'changes_detected': False,
        'commits_pushed': 0
    }

    try:
        logger.info("="*70)
        logger.info("STEP 1: Check for changes")
        logger.info("="*70)

        changes = get_git_status()
        changes = [c for c in changes if c.strip()]  # Filter empty lines

        if not changes:
            logger.info("No changes detected")
            push_report['status'] = 'no_changes'
        else:
            logger.info(f"Detected {len(changes)} file(s) with changes")
            for change in changes[:10]:  # Show first 10
                logger.info(f"  {change}")

            logger.info("="*70)
            logger.info("STEP 2: Get diff statistics")
            logger.info("="*70)
            diff_stat = get_git_diff_stat()
            if diff_stat:
                logger.info(f"Changes:\n{diff_stat}")

            logger.info("="*70)
            logger.info("STEP 3: Stage all changes")
            logger.info("="*70)
            if not stage_changes():
                push_report['status'] = 'staging_failed'
                raise Exception("Failed to stage changes")

            logger.info("="*70)
            logger.info("STEP 4: Create commit")
            logger.info("="*70)
            if create_commit(len(changes), diff_stat):
                push_report['changes_detected'] = True

                logger.info("="*70)
                logger.info("STEP 5: Push to remote")
                logger.info("="*70)
                if push_to_remote():
                    push_report['status'] = 'pushed'
                    push_report['commits_pushed'] = 1
                    push_report['files_changed'] = len(changes)

                    # Get files that were pushed
                    recent_changes = get_recent_changes()
                    push_report['files_list'] = recent_changes[:20]  # First 20

                    logger.info("="*70)
                    logger.info(f"Successfully pushed {len(changes)} changes to remote")
                    logger.info("="*70)
                else:
                    push_report['status'] = 'push_failed'
            else:
                push_report['status'] = 'commit_failed'

    except Exception as e:
        logger.error(f"Error: {e}", exc_info=True)
        push_report['status'] = 'error'
        push_report['error'] = str(e)

    finally:
        os.makedirs('logs', exist_ok=True)
        with open('logs/autonomous-git-push.json', 'w') as f:
            json.dump(push_report, f, indent=2)

        logger.info("="*70)
        logger.info(f"PUSH STATUS: {push_report['status']}")
        if push_report.get('commits_pushed', 0) > 0:
            logger.info(f"Commits pushed: {push_report['commits_pushed']}")
            logger.info(f"Files changed: {push_report.get('files_changed', 0)}")
        logger.info("="*70)

if __name__ == '__main__':
    main()
