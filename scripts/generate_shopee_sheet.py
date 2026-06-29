#!/usr/bin/env python3
import csv
import logging
import sys
from pathlib import Path
from typing import List

try:
    from openpyxl import Workbook
except ImportError:
    print('Missing dependency: openpyxl. Install with: pip install openpyxl')
    sys.exit(1)

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

MAPPING_FILE = Path('storage/uploaded_urls.csv')
OUTPUT_FILE = Path('planilhas/shopee_import.xlsx')


def load_mapping() -> List[dict]:
    if not MAPPING_FILE.exists():
        raise FileNotFoundError(f'Mapping file not found: {MAPPING_FILE}')
    rows = []
    with MAPPING_FILE.open('r', encoding='utf-8', newline='') as csvfile:
        reader = csv.DictReader(csvfile)
        for row in reader:
            if row.get('sku') and row.get('image_url'):
                rows.append({'sku': row['sku'].strip(), 'image_url': row['image_url'].strip()})
    return rows


def save_workbook(rows: List[dict]) -> None:
    OUTPUT_FILE.parent.mkdir(parents=True, exist_ok=True)
    workbook = Workbook()
    sheet = workbook.active
    sheet.title = 'Shopee'
    sheet.append(['item_id', 'image_url'])
    for row in rows:
        sheet.append([row['sku'], row['image_url']])
    workbook.save(OUTPUT_FILE)
    logger.info(f'Wrote Shopee import sheet to {OUTPUT_FILE}')


def main(argv=None) -> int:
    try:
        rows = load_mapping()
    except FileNotFoundError as exc:
        logger.error(exc)
        return 1

    if not rows:
        logger.error('No uploaded image mappings were found')
        return 1

    save_workbook(rows)
    return 0


if __name__ == '__main__':
    sys.exit(main())