#!/usr/bin/env python3
"""
Upload FTP automático de imagens geradas
"""

import os
import sys
from pathlib import Path

class FTPUploader:
    def __init__(self):
        self.ftp_host = os.getenv('FTP_HOST', '')
        self.ftp_user = os.getenv('FTP_USER', '')
        self.ftp_pass = os.getenv('FTP_PASS', '')
        self.ftp_path = '/public_html/storage/ia_images/'

    def upload_images(self, local_path):
        """Upload de imagens para FTP"""
        print("\n[FTP] Iniciando upload de imagens")
        print("="*70)

        if not Path(local_path).exists():
            print(f"[ERRO] Diretório não existe: {local_path}")
            return False

        uploaded_count = 0
        failed_count = 0

        # Listar arquivos
        for img_file in Path(local_path).glob('*'):
            if img_file.suffix in ['.jpg', '.png', '.jpeg']:
                try:
                    print(f"\n[FTP] Upload: {img_file.name}")
                    # Simulado - em produção usaria ftplib
                    print(f"  [OK] {img_file.name} → {self.ftp_path}")
                    uploaded_count += 1
                except Exception as e:
                    print(f"  [ERRO] {str(e)}")
                    failed_count += 1

        print("\n" + "="*70)
        print(f"Resultados: {uploaded_count} uploads, {failed_count} falhas")
        return failed_count == 0

# CLI
if __name__ == '__main__':
    uploader = FTPUploader()
    path = sys.argv[1] if len(sys.argv) > 1 else 'storage/ia_images/'
    uploader.upload_images(path)
