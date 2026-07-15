#!/usr/bin/env python3
import csv
import ftplib
import logging
import os
import sys
from pathlib import Path
from typing import Dict, Optional

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

INPUT_PROCESSED_ROOT = Path('storage/processed')
OUTPUT_MAPPING_FILE = Path('storage/uploaded_urls.csv')
SKU_MAPPING_FILE = Path('storage/sku_mapping.csv')
REMOTE_BASE_DIR = '/public_html/dev/uploads/olist'
WEB_BASE_URL = 'https://dev.shopvivaliz.com.br/uploads/olist'


def get_env_variable(name: str, alt_names: Optional[list[str]] = None) -> str:
    value = os.environ.get(name)
    found_name = name
    if not value and alt_names:
        for alt in alt_names:
            value = os.environ.get(alt)
            if value:
                found_name = alt
                break

    if not value:
        alt_text = f' or {", ".join(alt_names)}' if alt_names else ''
        raise EnvironmentError(f'Missing required environment variable: {name}{alt_text}')
    if found_name != name:
        logger.info(f'Using environment variable {found_name} for {name}')
    return value.strip()


def load_sku_mapping() -> Dict[str, str]:
    mapping: Dict[str, str] = {}
    if not SKU_MAPPING_FILE.exists():
        return mapping
    with SKU_MAPPING_FILE.open('r', encoding='utf-8', newline='') as csvfile:
        reader = csv.DictReader(csvfile)
        for row in reader:
            if row.get('sanitized_folder') and row.get('sku'):
                mapping[row['sanitized_folder'].strip()] = row['sku'].strip()
    return mapping


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
        host = get_env_variable('FTP_HOST', ['FTP_SERVER'])
        user = get_env_variable('FTP_USER', ['FTP_USERNAME'])
        password = get_env_variable('FTP_PASS', ['FTP_PASSWORD'])
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

    sku_mapping = load_sku_mapping()
    for sku_dir in sorted(INPUT_PROCESSED_ROOT.iterdir()):
        if not sku_dir.is_dir():
            continue

        original_sku = sku_mapping.get(sku_dir.name, sku_dir.name)
        remote_dir = f'{REMOTE_BASE_DIR}/{sku_dir.name}'
        try:
            create_remote_dirs(ftp, remote_dir)
            ftp.cwd(remote_dir)

            uploaded_urls = {}
            for variant in range(1, 5):
                local_file = sku_dir / f'{variant}.jpg'
                field_name = f'image_url_{variant}'
                if local_file.exists():
                    upload_file(ftp, local_file, f'{variant}.jpg')
                    uploaded_urls[field_name] = f'{WEB_BASE_URL}/{sku_dir.name}/{variant}.jpg'
                    logger.info(f'Uploaded SKU {original_sku} variant {variant} to {uploaded_urls[field_name]}')
                else:
                    uploaded_urls[field_name] = ''
                    logger.warning(f'SKU {sku_dir.name} missing processed file {local_file}; leaving {field_name} blank')

            if not any(uploaded_urls.values()):
                logger.warning(f'Skipping SKU {sku_dir.name}: no processed images uploaded')
                continue

            mapping_row = {'sku': original_sku, **uploaded_urls}
            mapping_rows.append(mapping_row)
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
        fieldnames = ['sku', 'image_url_1', 'image_url_2', 'image_url_3', 'image_url_4']
        writer = csv.DictWriter(csvfile, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(mapping_rows)

    logger.info(f'Uploaded images: {processed_count}; failures: {failed_count}')
    logger.info(f'Mapping written to {OUTPUT_MAPPING_FILE}')
    return 0 if failed_count == 0 else 1


if __name__ == '__main__':
    sys.exit(main())