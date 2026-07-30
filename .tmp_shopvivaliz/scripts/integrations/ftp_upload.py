import ftplib
import os
from dotenv import load_dotenv
import os

load_dotenv()

FTP_SERVER = os.getenv("FTP_SERVER")
FTP_USERNAME = os.getenv("FTP_USERNAME")
FTP_PASSWORD = os.getenv("FTP_PASSWORD")
BASE_URL = os.getenv("BASE_URL")

def upload_lote(imagens):
    ftp = ftplib.FTP(FTP_SERVER)
    ftp.login(FTP_USERNAME, FTP_PASSWORD)

    urls = []

    for img in imagens:
        nome = os.path.basename(img)
        with open(img, "rb") as f:
            ftp.storbinary(f"STOR {nome}", f)

        urls.append(f"{BASE_URL}/{nome}")

    ftp.quit()
    return urls
