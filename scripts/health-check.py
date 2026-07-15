#!/usr/bin/env python3
"""
Health Check - Valida saúde completa do sistema autônomo
Executa a cada hora via GitHub Actions + Windows Task Scheduler
"""
import os
import sys
import json
import subprocess
import glob
import time
from pathlib import Path
from datetime import datetime
import logging

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler('logs/health-check.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

print("="*70)
print("HEALTH CHECK v1.0 - Sistema Autônomo ShopVivaliz")
print("="*70)

def check_git_status():
    """Check git repository status"""
    checks = {
        'status': 'ok',
        'issues': []
    }

    try:
        # Check if we're in a git repo
        result = subprocess.run(['git', 'rev-parse', '--git-dir'], capture_output=True, text=True)
        if result.returncode != 0:
            checks['status'] = 'error'
            checks['issues'].append("Not a git repository")
            return checks

        # Check remote connectivity
        result = subprocess.run(['git', 'ls-remote', '--heads', 'origin'], capture_output=True, text=True, timeout=10)
        if result.returncode != 0:
            checks['status'] = 'warning'
            checks['issues'].append("Cannot connect to remote (origin)")
        else:
            checks['remote_connected'] = True

        # Check branch status
        result = subprocess.run(['git', 'status', '--short'], capture_output=True, text=True)
        uncommitted = len(result.stdout.strip().split('\n')) if result.stdout.strip() else 0
        checks['uncommitted_changes'] = uncommitted

        # Check last commit
        result = subprocess.run(['git', 'log', '-1', '--format=%ci'], capture_output=True, text=True)
        if result.returncode == 0:
            checks['last_commit'] = result.stdout.strip()

    except subprocess.TimeoutExpired:
        checks['status'] = 'warning'
        checks['issues'].append("Git command timeout")
    except Exception as e:
        checks['status'] = 'error'
        checks['issues'].append(f"Git check failed: {e}")

    return checks

def check_workflows():
    """Validate all YAML workflows"""
    checks = {
        'status': 'ok',
        'count': 0,
        'issues': []
    }

    try:
        import yaml
        yaml_files = glob.glob('.github/workflows/*.yml')
        checks['count'] = len(yaml_files)

        for wf_file in yaml_files:
            try:
                with open(wf_file, 'r', encoding='utf-8') as f:
                    yaml.safe_load(f)
            except Exception as e:
                checks['status'] = 'warning'
                checks['issues'].append(f"{Path(wf_file).name}: {str(e)[:50]}")

    except ImportError:
        checks['status'] = 'warning'
        checks['issues'].append("PyYAML not installed")
    except Exception as e:
        checks['status'] = 'error'
        checks['issues'].append(f"Workflow check failed: {e}")

    return checks

def check_python_files():
    """Check Python files for syntax errors"""
    checks = {
        'status': 'ok',
        'count': 0,
        'issues': []
    }

    try:
        py_files = glob.glob('scripts/*.py')
        checks['count'] = len(py_files)

        for py_file in py_files:
            try:
                with open(py_file, 'r', encoding='utf-8') as f:
                    compile(f.read(), py_file, 'exec')
            except SyntaxError as e:
                checks['status'] = 'warning'
                checks['issues'].append(f"{Path(py_file).name}: {str(e)[:50]}")

    except Exception as e:
        checks['status'] = 'error'
        checks['issues'].append(f"Python check failed: {e}")

    return checks

def check_api_endpoints():
    """Check if API endpoints are accessible"""
    checks = {
        'status': 'ok',
        'endpoints': [],
        'issues': []
    }

    api_endpoints = {
        '/api/health': 'Health endpoint',
        '/api/catalog': 'Catalog endpoint',
        '/api/products': 'Products endpoint',
    }

    # Note: This is a placeholder. In production, would test actual endpoints
    for endpoint, name in api_endpoints.items():
        checks['endpoints'].append({
            'path': endpoint,
            'name': name,
            'status': 'pending'  # Would be tested in production
        })

    return checks

def check_required_directories():
    """Check if all required directories exist"""
    checks = {
        'status': 'ok',
        'directories': [],
        'issues': []
    }

    required_dirs = [
        'logs',
        'scripts',
        'catalogo',
        'includes',
        'images',
        '.github/workflows'
    ]

    for dir_name in required_dirs:
        exists = Path(dir_name).exists()
        checks['directories'].append({
            'name': dir_name,
            'exists': exists
        })
        if not exists:
            checks['status'] = 'warning'
            checks['issues'].append(f"Missing directory: {dir_name}")

    return checks

def check_recent_logs():
    """Check recent operation logs"""
    checks = {
        'status': 'ok',
        'logs': []
    }

    log_files = [
        ('autonomous-sync.json', 'Last auto-sync'),
        ('tri-environment-sync.json', 'Tri-environment sync'),
        ('autonomous-git-push.json', 'Last git-push'),
        ('autonomous-ftp-deploy.json', 'Last FTP deploy'),
    ]

    for log_file, description in log_files:
        log_path = Path(f'logs/{log_file}')
        if log_path.exists():
            try:
                with open(log_path, 'r') as f:
                    data = json.load(f)
                checks['logs'].append({
                    'file': log_file,
                    'status': data.get('status', 'unknown'),
                    'timestamp': data.get('timestamp', 'unknown')
                })
            except:
                checks['logs'].append({
                    'file': log_file,
                    'status': 'error reading log',
                    'timestamp': 'unknown'
                })
        else:
            checks['logs'].append({
                'file': log_file,
                'status': 'not found',
                'timestamp': 'never'
            })

    return checks

def check_disk_space():
    """Check available disk space"""
    checks = {
        'status': 'ok',
        'issues': []
    }

    try:
        import shutil
        stat = shutil.disk_usage('.')
        usage_percent = (stat.used / stat.total) * 100

        checks['total_gb'] = stat.total / (1024**3)
        checks['used_gb'] = stat.used / (1024**3)
        checks['free_gb'] = stat.free / (1024**3)
        checks['usage_percent'] = usage_percent

        if usage_percent > 90:
            checks['status'] = 'error'
            checks['issues'].append(f"Disk usage critical: {usage_percent:.1f}%")
        elif usage_percent > 75:
            checks['status'] = 'warning'
            checks['issues'].append(f"Disk usage high: {usage_percent:.1f}%")

    except Exception as e:
        checks['status'] = 'warning'
        checks['issues'].append(f"Cannot check disk: {e}")

    return checks

def main():
    health_report = {
        'timestamp': datetime.now().isoformat(),
        'overall_status': 'ok',
        'checks': {}
    }

    logger.info("Starting health checks...")

    # Run all checks
    checks_to_run = {
        'git': check_git_status,
        'workflows': check_workflows,
        'python_files': check_python_files,
        'api_endpoints': check_api_endpoints,
        'directories': check_required_directories,
        'recent_logs': check_recent_logs,
        'disk_space': check_disk_space,
    }

    overall_status = 'ok'

    for check_name, check_func in checks_to_run.items():
        logger.info(f"Running check: {check_name}")
        try:
            result = check_func()
            health_report['checks'][check_name] = result

            status = result.get('status', 'unknown')
            logger.info(f"  Status: {status}")

            if status == 'error':
                overall_status = 'error'
            elif status == 'warning' and overall_status == 'ok':
                overall_status = 'warning'

        except Exception as e:
            logger.error(f"  Check failed: {e}")
            health_report['checks'][check_name] = {
                'status': 'error',
                'error': str(e)
            }
            overall_status = 'error'

    health_report['overall_status'] = overall_status

    # Save report
    os.makedirs('logs', exist_ok=True)
    with open('logs/health-check.json', 'w') as f:
        json.dump(health_report, f, indent=2)

    logger.info("="*70)
    logger.info(f"Health Check Complete - Status: {overall_status.upper()}")
    logger.info("="*70)

    # Print summary
    print("\n" + "="*70)
    print(f"OVERALL STATUS: {overall_status.upper()}")
    print("="*70)

    for check_name, result in health_report['checks'].items():
        status = result.get('status', 'unknown').upper()
        print(f"\n{check_name}: {status}")
        if result.get('issues'):
            for issue in result['issues'][:3]:
                print(f"  ⚠ {issue}")

    return 0 if overall_status != 'error' else 1

if __name__ == '__main__':
    sys.exit(main())
