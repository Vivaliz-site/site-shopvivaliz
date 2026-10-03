#!/usr/bin/env python3
"""
Compatibility uploader for legacy callers.

HostGator/FTP was retired. Files are now published into the persistent
ShopVivaliz webroot served by the Oracle production VM.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from site_public_storage import publish_file


class FTPUploader:
    """Legacy class name kept for import compatibility; no FTP is used."""

    def upload_images(self, local_path):
        root = Path(local_path)
        if not root.exists():
            print(f"[INFO] Diretorio nao existe: {local_path}")
            return True

        published = 0
        failures = []
        for image in root.glob("*"):
            if image.suffix.lower() not in {".jpg", ".jpeg", ".png", ".webp"}:
                continue
            try:
                url = publish_file(image, f"storage/ia_images/{image.name}")
                print(f"[PUBLICADO] {image.name} -> {url}")
                published += 1
            except Exception as exc:
                print(f"[FALHOU] {image.name}: {exc}")
                failures.append(image.name)

        print(f"Resultados: {published} publicados, {len(failures)} falhas")
        return not failures


if __name__ == "__main__":
    uploader = FTPUploader()
    path = sys.argv[1] if len(sys.argv) > 1 else "storage/ia_images/"
    raise SystemExit(0 if uploader.upload_images(path) else 1)
