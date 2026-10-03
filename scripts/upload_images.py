#!/usr/bin/env python3
import csv
import logging
import sys
from pathlib import Path

from site_public_storage import publish_file
from typing import Dict

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

INPUT_PROCESSED_ROOT = Path('storage/processed')
OUTPUT_MAPPING_FILE = Path('storage/uploaded_urls.csv')
SKU_MAPPING_FILE = Path('storage/sku_mapping.csv')
REMOTE_BASE_DIR = '/public_html/dev/uploads/olist'
WEB_BASE_URL = 'https://shopvivaliz.com.br/uploads/olist'


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


def main(argv=None) -> int:
    if not INPUT_PROCESSED_ROOT.exists():
        logger.error(f'Processed image folder not found: {INPUT_PROCESSED_ROOT}')
        return 1

    mapping_rows = []
    processed_count = 0
    failed_count = 0
    sku_mapping = load_sku_mapping()

    for sku_dir in sorted(INPUT_PROCESSED_ROOT.iterdir()):
        if not sku_dir.is_dir():
            continue

        original_sku = sku_mapping.get(sku_dir.name, sku_dir.name)
        uploaded_urls = {}
        try:
            for variant in range(1, 5):
                local_file = sku_dir / f'{variant}.jpg'
                field_name = f'image_url_{variant}'
                if not local_file.exists():
                    uploaded_urls[field_name] = ''
                    logger.warning(
                        f'SKU {sku_dir.name} missing processed file {local_file}; '
                        f'leaving {field_name} blank'
                    )
                    continue

                relative = f'uploads/olist/{sku_dir.name}/{variant}.jpg'
                uploaded_urls[field_name] = publish_file(
                    local_file,
                    relative,
                    base_url=WEB_BASE_URL.removesuffix('/uploads/olist'),
                )
                logger.info(
                    f'Published SKU {original_sku} variant {variant} '
                    f'to {uploaded_urls[field_name]}'
                )

            if not any(uploaded_urls.values()):
                logger.warning(f'Skipping SKU {sku_dir.name}: no processed images published')
                continue

            mapping_rows.append({'sku': original_sku, **uploaded_urls})
            processed_count += 1
        except Exception as exc:
            logger.error(f'Failed to publish SKU {sku_dir.name}: {exc}')
            failed_count += 1

    OUTPUT_MAPPING_FILE.parent.mkdir(parents=True, exist_ok=True)
    with OUTPUT_MAPPING_FILE.open('w', newline='', encoding='utf-8') as csvfile:
        fieldnames = ['sku', 'image_url_1', 'image_url_2', 'image_url_3', 'image_url_4']
        writer = csv.DictWriter(csvfile, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(mapping_rows)

    logger.info(f'Published images: {processed_count}; failures: {failed_count}')
    logger.info(f'Mapping written to {OUTPUT_MAPPING_FILE}')
    return 0 if failed_count == 0 else 1


if __name__ == '__main__':
    sys.exit(main())