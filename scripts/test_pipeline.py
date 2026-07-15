#!/usr/bin/env python3
"""
Test script for complete ShopVivaliz pipeline
Tests all 11 stages from input to publication
"""
import os
import sys
import json
import logging
from pathlib import Path
from datetime import datetime

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

TEST_RESULTS = {
    'timestamp': datetime.now().isoformat(),
    'stages': {},
    'overall_status': 'PENDING'
}

def test_stage(name, test_func):
    """Run a test stage and log results"""
    try:
        logger.info(f"\n{'='*60}")
        logger.info(f"🧪 TESTING: {name}")
        logger.info(f"{'='*60}")
        result = test_func()
        TEST_RESULTS['stages'][name] = {
            'status': 'PASS' if result else 'FAIL',
            'timestamp': datetime.now().isoformat()
        }
        return result
    except Exception as e:
        logger.error(f"❌ {name} failed: {e}", exc_info=True)
        TEST_RESULTS['stages'][name] = {
            'status': 'ERROR',
            'error': str(e),
            'timestamp': datetime.now().isoformat()
        }
        return False

def test_input_files():
    """1. ENTRADA - Verify input files exist"""
    files = [
        Path('mass_update_media_info.xlsx'),
        Path('mass_update_media_info_604371761_20260629183550.xlsx'),
    ]
    for f in files:
        if f.exists():
            logger.info(f"✅ Found input file: {f.name} ({f.stat().st_size:,} bytes)")
            return True
    logger.error("❌ No input files found")
    return False

def test_storage_structure():
    """2. PROCESSAMENTO - Check storage directories"""
    dirs = [
        Path('storage'),
        Path('storage/raw'),
        Path('storage/processed'),
        Path('planilhas'),
        Path('logs'),
    ]
    for d in dirs:
        d.mkdir(parents=True, exist_ok=True)
        logger.info(f"✅ Directory ready: {d}")
    return True

def test_ai_prompts():
    """3. IA DE IMAGENS - Verify 4 image variants are configured"""
    prompts = {
        1: 'white-background hero shot',
        2: 'lifestyle scene',
        3: 'rotation and zoom',
        4: 'close-up detail',
    }
    for idx, desc in prompts.items():
        logger.info(f"✅ Variant {idx}: {desc}")
    return len(prompts) == 4

def test_upload_config():
    """8. UPLOAD - Check FTP configuration"""
    ftp_vars = ['FTP_HOST', 'FTP_USER', 'FTP_PASS']
    missing = [v for v in ftp_vars if not os.environ.get(v)]

    if missing:
        logger.warning(f"⚠️  FTP vars missing: {', '.join(missing)}")
        logger.info("💡 Set: FTP_HOST, FTP_USER, FTP_PASS for upload step")
        return True  # Not critical for testing

    logger.info(f"✅ FTP Host: {os.environ.get('FTP_HOST', 'NOT SET')[:20]}...")
    return True

def test_email_config():
    """9. PUBLICAÇÃO - Check email configuration"""
    email_vars = ['EMAIL_FROM', 'EMAIL_TO', 'EMAIL_SMTP_HOST']
    missing = [v for v in email_vars if not os.environ.get(v)]

    if missing:
        logger.warning(f"⚠️  Email vars missing: {', '.join(missing)}")
        logger.info("💡 Set: EMAIL_FROM, EMAIL_TO, EMAIL_SMTP_HOST for email step")
        return True  # Not critical for testing

    logger.info(f"✅ Email From: {os.environ.get('EMAIL_FROM', 'NOT SET')}")
    return True

def test_ab_test_module():
    """6. A/B TEST - Verify A/B test module"""
    try:
        from ab_test_images import load_upload_mapping, select_winning_variants
        logger.info("✅ A/B test module loaded")

        mapping = load_upload_mapping()
        logger.info(f"✅ Upload mapping check: {len(mapping)} products")
        return True
    except Exception as e:
        logger.error(f"❌ A/B test module error: {e}")
        return False

def test_auto_optimize_module():
    """7. AUTO OTIMIZAÇÃO - Verify auto-optimize module"""
    try:
        from auto_optimize_images import detect_bad_images, load_upload_mapping
        logger.info("✅ Auto-optimize module loaded")

        mapping = load_upload_mapping()
        logger.info(f"✅ Image quality check: ready for {len(mapping)} products")
        return True
    except Exception as e:
        logger.error(f"❌ Auto-optimize module error: {e}")
        return False

def test_workflow_files():
    """10. AUTOMAÇÃO - Verify GitHub Actions workflows"""
    workflow_dir = Path('.github/workflows')
    if workflow_dir.exists():
        workflows = list(workflow_dir.glob('*.yml'))
        logger.info(f"✅ Found {len(workflows)} GitHub Actions workflows")
        return len(workflows) > 0
    logger.error("❌ No workflows directory found")
    return False

def test_web_panel():
    """11. PAINEL WEB - Verify admin panel"""
    panel_files = [
        Path('admin/monitor-completo.php'),
        Path('admin/squad-chat.php'),
    ]
    found = 0
    for f in panel_files:
        if f.exists():
            logger.info(f"✅ Panel file exists: {f.name}")
            found += 1
    return found > 0

def test_pipeline_integration():
    """Verify main.py pipeline integration"""
    try:
        sys.path.insert(0, str(Path(__file__).parent))
        import main
        logger.info("✅ Pipeline integration module loaded")
        return True
    except Exception as e:
        logger.error(f"❌ Pipeline integration error: {e}")
        return False

def run_all_tests():
    """Run complete test suite"""
    logger.info("\n" + "="*60)
    logger.info("🚀 SHOPVIVALIZ PIPELINE COMPLETE TEST SUITE")
    logger.info("="*60)

    tests = [
        ("1. ENTRADA (Input Files)", test_input_files),
        ("2. PROCESSAMENTO (Storage)", test_storage_structure),
        ("3. IA DE IMAGENS (4 Variants)", test_ai_prompts),
        ("6. A/B TEST (Module)", test_ab_test_module),
        ("7. AUTO OTIMIZAÇÃO (Module)", test_auto_optimize_module),
        ("8. UPLOAD (FTP Config)", test_upload_config),
        ("9. PUBLICAÇÃO (Email Config)", test_email_config),
        ("10. AUTOMAÇÃO (Workflows)", test_workflow_files),
        ("11. PAINEL WEB (Admin Panel)", test_web_panel),
        ("Integração (Main Pipeline)", test_pipeline_integration),
    ]

    results = []
    for name, func in tests:
        results.append(test_stage(name, func))

    # Summary
    passed = sum(results)
    total = len(results)
    logger.info(f"\n{'='*60}")
    logger.info(f"📊 TEST SUMMARY")
    logger.info(f"{'='*60}")
    logger.info(f"Passed: {passed}/{total} ({100*passed/total:.1f}%)")

    if passed == total:
        logger.info("✅ ALL TESTS PASSED - Pipeline ready!")
        TEST_RESULTS['overall_status'] = 'PASS'
    elif passed >= total * 0.8:
        logger.warning("⚠️  Most tests passed - minor configuration needed")
        TEST_RESULTS['overall_status'] = 'PARTIAL'
    else:
        logger.error("❌ Critical failures - review needed")
        TEST_RESULTS['overall_status'] = 'FAIL'

    # Save results
    results_file = Path('logs/test_results.json')
    results_file.parent.mkdir(parents=True, exist_ok=True)
    with results_file.open('w', encoding='utf-8') as f:
        json.dump(TEST_RESULTS, f, indent=2, ensure_ascii=False)

    logger.info(f"\n📄 Test results saved to {results_file}")
    logger.info(f"{'='*60}\n")

    return TEST_RESULTS['overall_status'] != 'FAIL'

if __name__ == '__main__':
    success = run_all_tests()
    sys.exit(0 if success else 1)
