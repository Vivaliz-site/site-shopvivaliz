#!/usr/bin/env python3
import os
import sys
from pathlib import Path

from import_shopee import main as import_shopee_main
from generate_ai_images import main as generate_ai_images_main
from upload_images import main as upload_images_main
from ab_test_images import main as ab_test_main
from auto_optimize_images import main as auto_optimize_main
from generate_shopee_sheet import main as generate_shopee_sheet_main
from send_email import main as send_email_main

UPLOAD_MAPPING_FILE = Path('storage/uploaded_urls.csv')
SHOPEE_SHEET_FILE = Path('planilhas/shopee_import.xlsx')


def env_var_present(*names: str) -> bool:
    return any(bool(os.environ.get(name)) for name in names)


def env_vars_present(*requirements: tuple | str) -> bool:
    for item in requirements:
        if isinstance(item, str):
            if not os.environ.get(item):
                return False
        else:
            if not env_var_present(*item):
                return False
    return True


def missing_env_names(*requirements: tuple | str) -> str:
    missing = []
    for item in requirements:
        if isinstance(item, str):
            if not os.environ.get(item):
                missing.append(item)
        else:
            if not env_var_present(*item):
                missing.append('(' + ' or '.join(item) + ')')
    return ', '.join(missing)


if __name__ == '__main__':
    upload_skipped = False

    steps = [
        ('import_shopee', import_shopee_main, [sys.argv[1:]], None),
        ('generate_ai_images', generate_ai_images_main, [], None),
        (
            'upload_images',
            upload_images_main,
            [],
            (('FTP_HOST', 'FTP_SERVER'), ('FTP_USER', 'FTP_USERNAME'), ('FTP_PASS', 'FTP_PASSWORD')),
        ),
        ('ab_test_images', ab_test_main, [], None),
        ('auto_optimize_images', auto_optimize_main, [], None),
        (
            'generate_shopee_sheet',
            generate_shopee_sheet_main,
            [],
            None,
        ),
        (
            'send_email',
            send_email_main,
            [],
            (('SMTP_HOST', 'EMAIL_SMTP_HOST'), ('SMTP_PORT', 'EMAIL_SMTP_PORT'), ('SMTP_USER', 'EMAIL_USER'), ('SMTP_PASS', 'EMAIL_PASSWORD'), 'EMAIL_FROM', 'EMAIL_TO'),
        ),
    ]

    for name, func, args, required_env in steps:
        print(f'=== RUNNING: {name} ===')

        if required_env and not env_vars_present(*required_env):
            print(f'WARNING: skipping {name} because required env vars are missing: {missing_env_names(*required_env)}')
            if name == 'upload_images':
                upload_skipped = True
            continue

        if name == 'generate_shopee_sheet' and not UPLOAD_MAPPING_FILE.exists():
            print('WARNING: skipping generate_shopee_sheet because upload mapping file is missing')
            continue

        if name == 'send_email' and not SHOPEE_SHEET_FILE.exists():
            print('WARNING: skipping send_email because Shopee sheet attachment is missing')
            continue

        result = func(*args)
        if result != 0:
            print(f'ERROR: step {name} failed with code {result}')
            sys.exit(result)

    sys.exit(0)
