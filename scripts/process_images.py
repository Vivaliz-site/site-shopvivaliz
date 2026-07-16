#!/usr/bin/env python3
import logging
import os
import sys
from pathlib import Path
from typing import Optional

try:
    from PIL import Image, ImageEnhance, ImageOps
except ImportError:
    print('Missing dependency: Pillow. Install with: pip install Pillow')
    sys.exit(1)

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

INPUT_RAW_ROOT = Path('storage/raw')
OUTPUT_PROCESSED_ROOT = Path('storage/processed')
TARGET_SIZE = (1000, 1000)


def process_image(source_path: Path, target_path: Path) -> bool:
    try:
        with Image.open(source_path) as image:
            image = image.convert('RGB')
            image = ImageOps.fit(image, TARGET_SIZE, Image.LANCZOS)
            image = ImageEnhance.Sharpness(image).enhance(1.2)
            image = ImageEnhance.Contrast(image).enhance(1.1)
            target_path.parent.mkdir(parents=True, exist_ok=True)
            image.save(target_path, format='JPEG', quality=92, optimize=True)
        return True
    except Exception as exc:
        logger.error(f'Failed to process image {source_path}: {exc}')
        return False


def main(argv=None) -> int:
    processed_count = 0
    failed_count = 0

    if not INPUT_RAW_ROOT.exists():
        logger.error(f'Raw image directory not found: {INPUT_RAW_ROOT}')
        return 1

    for sku_dir in sorted(INPUT_RAW_ROOT.iterdir()):
        if not sku_dir.is_dir():
            continue
        source_file = sku_dir / '1.jpg'
        if not source_file.exists():
            logger.warning(f'Skipping SKU {sku_dir.name}: missing source image')
            continue

        target_file = OUTPUT_PROCESSED_ROOT / sku_dir.name / '1.jpg'
        logger.info(f'Processing SKU {sku_dir.name}')
        if process_image(source_file, target_file):
            processed_count += 1
        else:
            failed_count += 1

    if processed_count == 0:
        logger.warning('No processed images were created. Check storage/raw contents.')

    logger.info(f'Processed images: {processed_count}; failures: {failed_count}')
    return 0 if failed_count == 0 else 1


if __name__ == '__main__':
    sys.exit(main())