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
        """Upload de imagens para FTP.

        NOTA: o deploy FTP/HostGator esta desativado em producao (ver
        CLAUDE.md) -- a producao real roda via cron na VM Oracle. Este
        metodo so faz sentido se FTP_HOST/FTP_USER estiverem configurados
        explicitamente para um uso pontual/manual.
        """
        print("\n[FTP] Iniciando upload de imagens")
        print("="*70)

        if not Path(local_path).exists():
            print(f"[INFO] Diretorio nao existe: {local_path}")
            return True

        uploaded_count = 0
        failed_count = 0
        failed_files = []

        if not self.ftp_host or not self.ftp_user:
            print("[ERRO] FTP_HOST/FTP_USER nao configurados -- nenhum upload foi feito.")
            print("Este script nao envia nada sem credenciais reais (nao ha simulacao de sucesso).")
            return False

        try:
            from ftplib import FTP_TLS
            ftp = FTP_TLS(self.ftp_host, self.ftp_user, self.ftp_pass)
            ftp.prot_p()
        except Exception as e:
            print(f"[ERRO] Nao foi possivel conectar ao FTP: {e}")
            return False

        for img_file in Path(local_path).glob('*'):
            if img_file.suffix in ['.jpg', '.png', '.jpeg']:
                try:
                    with open(img_file, 'rb') as f:
                        ftp.storbinary(f'STOR {self.ftp_path}{img_file.name}', f)
                    print(f"[ENVIADO] {img_file.name}")
                    uploaded_count += 1
                except Exception as e:
                    print(f"[FALHOU] {img_file.name}: {e}")
                    failed_count += 1
                    failed_files.append(img_file.name)

        ftp.quit()

        print("\n" + "="*70)
        print(f"Resultados: {uploaded_count} uploads, {failed_count} falhas")
        if failed_files:
            print(f"Arquivos que falharam: {', '.join(failed_files)}")
        return failed_count == 0

# CLI
if __name__ == '__main__':
    uploader = FTPUploader()
    path = sys.argv[1] if len(sys.argv) > 1 else 'storage/ia_images/'
    uploader.upload_images(path)
