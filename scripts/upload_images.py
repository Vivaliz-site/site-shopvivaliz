#!/usr/bin/env python3
import csv
import ftplib
import logging
import os
import sys
from pathlib import Path
from typing import Optional

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

INPUT_PROCESSED_ROOT = Path('storage/processed')
OUTPUT_MAPPING_FILE = Path('storage/uploaded_urls.csv')
REMOTE_BASE_DIR = '/public_html/dev/uploads/olist'
WEB_BASE_URL = 'https://dev.shopvivaliz.com.br/uploads/olist'


def get_env_variable(name: str) -> str:
    value = os.environ.get(name)
    if not value:
        raise EnvironmentError(f'Missing required environment variable: {name}')
    return value.strip()


def create_remote_dirs(ftp: ftplib.FTP, remote_dir: str) -> None:
    current = ''
    for segment in remote_dir.strip('/').split('/'):
        if not segment:
            continue
        current = f'{current}/{segment}'
        try:
            ftp.mkd(current)
        except ftplib.error_perm as exc:
            if '550' in str(exc):
                continue
            raise


def upload_file(ftp: ftplib.FTP, local_file: Path, remote_path: str) -> None:
    with local_file.open('rb') as f:
        ftp.storbinary(f'STOR {remote_path}', f)


def main(argv=None) -> int:
    try:
        host = get_env_variable('FTP_HOST')
        user = get_env_variable('FTP_USER')
        password = get_env_variable('FTP_PASS')
    except EnvironmentError as exc:
        logger.error(exc)
        return 1

    if not INPUT_PROCESSED_ROOT.exists():
        logger.error(f'Processed image folder not found: {INPUT_PROCESSED_ROOT}')
        return 1

    mapping_rows = []
    processed_count = 0
    failed_count = 0

    try:
        ftp = ftplib.FTP_TLS(host, timeout=30)
        ftp.login(user, password)
        ftp.prot_p()
        ftp.cwd('/')
    except Exception as exc:
        logger.error(f'FTP connection failed: {exc}')
        return 1

    try:
        create_remote_dirs(ftp, REMOTE_BASE_DIR)
    except Exception as exc:
        logger.error(f'Failed to prepare remote directories: {exc}')
        ftp.quit()
        return 1

    for sku_dir in sorted(INPUT_PROCESSED_ROOT.iterdir()):
        if not sku_dir.is_dir():
            continue
        local_file = sku_dir / '1.jpg'
        if not local_file.exists():
            logger.warning(f'Skipping SKU {sku_dir.name}: no processed image found')
            continue

        remote_dir = f'{REMOTE_BASE_DIR}/{sku_dir.name}'
        remote_file = f'{remote_dir}/1.jpg'
        try:
            create_remote_dirs(ftp, remote_dir)
            ftp.cwd(remote_dir)
            upload_file(ftp, local_file, '1.jpg')
            image_url = f'{WEB_BASE_URL}/{sku_dir.name}/1.jpg'
            mapping_rows.append({'sku': sku_dir.name, 'image_url': image_url})
            logger.info(f'Uploaded SKU {sku_dir.name} to {image_url}')
            processed_count += 1
        except Exception as exc:
            logger.error(f'Failed to upload SKU {sku_dir.name}: {exc}')
            failed_count += 1
        finally:
            ftp.cwd('/')

    ftp.quit()

    if OUTPUT_MAPPING_FILE.parent:
        OUTPUT_MAPPING_FILE.parent.mkdir(parents=True, exist_ok=True)
    with OUTPUT_MAPPING_FILE.open('w', newline='', encoding='utf-8') as csvfile:
        writer = csv.DictWriter(csvfile, fieldnames=['sku', 'image_url'])
        writer.writeheader()
        writer.writerows(mapping_rows)

    logger.info(f'Uploaded images: {processed_count}; failures: {failed_count}')
    logger.info(f'Mapping written to {OUTPUT_MAPPING_FILE}')
    return 0 if failed_count == 0 else 1


if __name__ == '__main__':
    sys.exit(main())