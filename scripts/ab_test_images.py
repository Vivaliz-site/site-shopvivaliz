#!/usr/bin/env python3
import csv
import json
import logging
import os
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Optional

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

AB_TEST_FILE = Path('storage/ab_test_results.json')
UPLOAD_MAPPING_FILE = Path('storage/uploaded_urls.csv')


def load_ab_test_data() -> Dict:
    if AB_TEST_FILE.exists():
        with AB_TEST_FILE.open('r', encoding='utf-8') as f:
            return json.load(f)
    return {'tests': {}}


def save_ab_test_data(data: Dict) -> None:
    AB_TEST_FILE.parent.mkdir(parents=True, exist_ok=True)
    with AB_TEST_FILE.open('w', encoding='utf-8') as f:
        json.dump(data, f, indent=2, ensure_ascii=False)


def load_upload_mapping() -> Dict[str, List[str]]:
    mapping = {}
    if not UPLOAD_MAPPING_FILE.exists():
        return mapping
    with UPLOAD_MAPPING_FILE.open('r', encoding='utf-8', newline='') as csvfile:
        reader = csv.DictReader(csvfile)
        for row in reader:
            sku = row.get('sku', '').strip()
            if not sku:
                continue
            images = [
                row.get('image_url_1', '').strip(),
                row.get('image_url_2', '').strip(),
                row.get('image_url_3', '').strip(),
                row.get('image_url_4', '').strip(),
            ]
            images = [img for img in images if img]
            if images:
                mapping[sku] = images
    return mapping


def initialize_ab_tests(mapping: Dict[str, List[str]]) -> Dict:
    data = load_ab_test_data()
    timestamp = datetime.now().isoformat()

    for sku, images in mapping.items():
        if sku not in data['tests']:
            data['tests'][sku] = {
                'started': timestamp,
                'variants': {}
            }

        for idx, image_url in enumerate(images, 1):
            variant_name = f'variant_{idx}'
            if variant_name not in data['tests'][sku]['variants']:
                data['tests'][sku]['variants'][variant_name] = {
                    'image_url': image_url,
                    'clicks': 0,
                    'sales': 0,
                    'conversions': 0,
                    'ctr': 0.0,
                    'updated': timestamp
                }

    save_ab_test_data(data)
    logger.info(f'Initialized A/B tests for {len(mapping)} products')
    return data


def select_winning_variants(data: Dict) -> Dict[str, Dict]:
    winners = {}

    for sku, test_data in data.get('tests', {}).items():
        variants = test_data.get('variants', {})
        if not variants:
            continue

        best_variant = None
        best_ctr = -1

        for variant_name, variant_data in variants.items():
            ctr = variant_data.get('ctr', 0)
            if ctr > best_ctr:
                best_ctr = ctr
                best_variant = (variant_name, variant_data)

        if best_variant:
            winners[sku] = {
                'variant': best_variant[0],
                'image_url': best_variant[1]['image_url'],
                'ctr': best_ctr,
                'conversions': best_variant[1]['conversions']
            }

    logger.info(f'Selected winning variants for {len(winners)} products')
    return winners


def generate_ab_test_report() -> str:
    data = load_ab_test_data()
    winners = select_winning_variants(data)

    report = "=== A/B TEST REPORT ===\n\n"
    report += f"Total products tested: {len(data.get('tests', {}))}\n"
    report += f"Winners selected: {len(winners)}\n\n"

    for sku, winner in list(winners.items())[:5]:
        report += f"SKU: {sku}\n"
        report += f"  Winner: {winner['variant']}\n"
        report += f"  CTR: {winner['ctr']:.2%}\n"
        report += f"  Conversions: {winner['conversions']}\n\n"

    return report


def main() -> int:
    try:
        logger.info('Starting A/B Test analysis...')

        mapping = load_upload_mapping()
        if not mapping:
            logger.warning('No products found in upload mapping')
            return 0

        data = initialize_ab_tests(mapping)
        winners = select_winning_variants(data)

        report = generate_ab_test_report()
        logger.info(report)

        report_file = Path('logs/ab_test_report.txt')
        report_file.parent.mkdir(parents=True, exist_ok=True)
        with report_file.open('w', encoding='utf-8') as f:
            f.write(report)

        logger.info(f'A/B test report saved to {report_file}')
        return 0

    except Exception as e:
        logger.error(f'A/B Test analysis failed: {e}')
        return 1


if __name__ == '__main__':
    exit(main())
