"""
Upload FTP com retry exponencial.
Variáveis de ambiente: FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, FTP_PORT, FTP_REMOTE_DIR
"""
import ftplib
import os
import time
from pathlib import Path


def _connect() -> ftplib.FTP:
    ftp = ftplib.FTP()
    ftp.connect(
        host=os.environ["FTP_SERVER"],
        port=int(os.environ.get("FTP_PORT", 21)),
        timeout=30,
    )
    ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
    ftp.set_pasv(True)
    return ftp


def _ensure_dir(ftp: ftplib.FTP, remote_dir: str):
    parts = remote_dir.strip("/").split("/")
    current = ""
    for part in parts:
        current += f"/{part}"
        try:
            ftp.mkd(current)
        except ftplib.error_perm:
            pass


def upload(local_path: str, remote_subdir: str = "") -> str:
    """
    Faz upload do arquivo e retorna a URL pública.
    remote_subdir: subdiretório dentro de FTP_REMOTE_DIR (ex: 'ia_images/sku123')
    """
    local = Path(local_path)
    base_dir = os.environ.get("FTP_REMOTE_DIR", "/public_html/uploads").rstrip("/")
    remote_dir = f"{base_dir}/{remote_subdir}".rstrip("/") if remote_subdir else base_dir
    remote_path = f"{remote_dir}/{local.name}"

    domain = os.environ.get("SITE_DOMAIN", "shopvivaliz.com.br")
    public_url = f"https://{domain}/uploads/{remote_subdir}/{local.name}".replace("//", "/").replace("https:/", "https://")

    for attempt in range(1, 4):
        try:
            ftp = _connect()
            _ensure_dir(ftp, remote_dir)
            with open(local_path, "rb") as f:
                ftp.storbinary(f"STOR {remote_path}", f)
            ftp.quit()
            return public_url
        except Exception as exc:
            if attempt == 3:
                raise RuntimeError(f"FTP upload falhou após 3 tentativas: {exc}") from exc
            time.sleep(2 ** attempt)
