#!/usr/bin/env python3
import csv
import json
import logging
import os
import sys
from pathlib import Path
from typing import Dict, List, Optional

try:
    from PIL import Image
except ImportError:
    print("Missing dependency: Pillow. Install with: pip install Pillow")
    sys.exit(1)

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

UPLOAD_MAPPING_FILE = Path('storage/uploaded_urls.csv')
OPTIMIZATION_LOG = Path('logs/optimization_log.json')
PROCESSED_ROOT = Path('storage/processed')
MIN_IMAGE_SIZE = 100_000  # 100KB


def load_optimization_log() -> Dict:
    if OPTIMIZATION_LOG.exists():
        with OPTIMIZATION_LOG.open('r', encoding='utf-8') as f:
            return json.load(f)
    return {'optimized': {}, 'issues': []}


def save_optimization_log(log: Dict) -> None:
    OPTIMIZATION_LOG.parent.mkdir(parents=True, exist_ok=True)
    with OPTIMIZATION_LOG.open('w', encoding='utf-8') as f:
        json.dump(log, f, indent=2, ensure_ascii=False)


def load_upload_mapping() -> Dict[str, Dict]:
    mapping = {}
    if not UPLOAD_MAPPING_FILE.exists():
        return mapping
    with UPLOAD_MAPPING_FILE.open('r', encoding='utf-8', newline='') as csvfile:
        reader = csv.DictReader(csvfile)
        for row in reader:
            sku = row.get('sku', '').strip()
            if not sku:
                continue
            mapping[sku] = {
                'image_url_1': row.get('image_url_1', '').strip(),
                'image_url_2': row.get('image_url_2', '').strip(),
                'image_url_3': row.get('image_url_3', '').strip(),
                'image_url_4': row.get('image_url_4', '').strip(),
            }
    return mapping


def detect_bad_images(mapping: Dict[str, Dict]) -> Dict[str, List[str]]:
    bad_images = {}

    for sku, images_dict in mapping.items():
        bad_variants = []

        for variant_name, image_url in images_dict.items():
            if not image_url:
                bad_variants.append(variant_name)
                continue

            # Check if image file exists in processed storage
            folder_name = sku.replace('/', '_')
            processed_folder = PROCESSED_ROOT / folder_name

            if not processed_folder.exists():
                bad_variants.append(variant_name)
                continue

            # Check image quality metrics
            image_files = list(processed_folder.glob('*.jpg')) + list(processed_folder.glob('*.png'))

            for img_file in image_files:
                try:
                    with Image.open(img_file) as img:
                        width, height = img.size

                        # Check minimum dimensions
                        if width < 300 or height < 300:
                            bad_variants.append(variant_name)
                            logger.warning(f'{img_file}: Too small ({width}x{height})')
                            break

                        # Check file size
                        file_size = img_file.stat().st_size
                        if file_size < 50_000:  # Less than 50KB
                            bad_variants.append(variant_name)
                            logger.warning(f'{img_file}: Too small file size ({file_size} bytes)')
                            break

                except Exception as e:
                    bad_variants.append(variant_name)
                    logger.warning(f'Error checking {img_file}: {e}')
                    break

        if bad_variants:
            bad_images[sku] = bad_variants

    return bad_images


def flag_for_regeneration(sku: str, variants: List[str]) -> None:
    log = load_optimization_log()

    if sku not in log['optimized']:
        log['optimized'][sku] = {
            'regeneration_count': 0,
            'bad_variants': variants,
            'last_updated': str(Path.cwd())
        }

    log['optimized'][sku]['regeneration_count'] += 1
    log['optimized'][sku]['bad_variants'] = variants

    save_optimization_log(log)


def generate_optimization_report(bad_images: Dict[str, List[str]]) -> str:
    report = "=== IMAGE OPTIMIZATION REPORT ===\n\n"
    report += f"Products with bad images: {len(bad_images)}\n"
    report += f"Total variants to regenerate: {sum(len(v) for v in bad_images.values())}\n\n"

    report += "PRODUCTS REQUIRING REGENERATION:\n"
    for sku, variants in list(bad_images.items())[:10]:
        report += f"  {sku}: {', '.join(variants)}\n"

    if len(bad_images) > 10:
        report += f"\n  ... and {len(bad_images) - 10} more products\n"

    report += "\nRECOMMENDATIONS:\n"
    report += "1. Regenerate flagged image variants with refined prompts\n"
    report += "2. Increase image resolution requirements\n"
    report += "3. Add quality scoring to image generation\n"
    report += "4. Implement automatic retry for failed generations\n"

    return report


def main() -> int:
    try:
        logger.info('Starting auto-optimization analysis...')

        mapping = load_upload_mapping()
        if not mapping:
            logger.warning('No products found in upload mapping')
            return 0

        logger.info(f'Analyzing {len(mapping)} products for image quality...')
        bad_images = detect_bad_images(mapping)

        if bad_images:
            logger.info(f'Found {len(bad_images)} products with quality issues')
            for sku, variants in bad_images.items():
                flag_for_regeneration(sku, variants)
        else:
            logger.info('All images passed quality checks')

        report = generate_optimization_report(bad_images)
        logger.info(report)

        report_file = Path('logs/optimization_report.txt')
        report_file.parent.mkdir(parents=True, exist_ok=True)
        with report_file.open('w', encoding='utf-8') as f:
            f.write(report)

        logger.info(f'Optimization report saved to {report_file}')
        return 0

    except Exception as e:
        logger.error(f'Auto-optimization failed: {e}', exc_info=True)
        return 1


if __name__ == '__main__':
    exit(main())
