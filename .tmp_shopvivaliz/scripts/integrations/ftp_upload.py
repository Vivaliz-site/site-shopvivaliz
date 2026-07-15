import ftplib
import os
from dotenv import load_dotenv
import os

load_dotenv()

FTP_HOST = os.getenv("FTP_HOST")
FTP_USER = os.getenv("FTP_USER")
FTP_PASS = os.getenv("FTP_PASS")
BASE_URL = os.getenv("BASE_URL")

def upload_lote(imagens):
    ftp = ftplib.FTP(FTP_HOST)
    ftp.login(FTP_USER, FTP_PASS)

    urls = []

    for img in imagens:
        nome = os.path.basename(img)
        with open(img, "rb") as f:
            ftp.storbinary(f"STOR {nome}", f)

        urls.append(f"{BASE_URL}/{nome}")

    ftp.quit()
    return urls
