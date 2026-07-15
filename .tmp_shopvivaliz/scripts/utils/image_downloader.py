import requests
import os

def baixar_imagem(url, sku):
    if not url:
        return None

    os.makedirs("storage/original", exist_ok=True)
    path = f"storage/original/{sku}.jpg"

    try:
        r = requests.get(url, timeout=15)
        if r.status_code == 200:
            with open(path, "wb") as f:
                f.write(r.content)
            return path
    except:
        pass

    return None
