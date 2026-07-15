import pandas as pd
import requests
import os
import ftplib
import sys
import re
from pathlib import Path
from dotenv import load_dotenv

ROOT_DIR = Path(__file__).resolve().parents[1]
if str(ROOT_DIR) not in sys.path:
    sys.path.insert(0, str(ROOT_DIR))

from ia.generate import gerar_imagens as gerar_imagens_ia

load_dotenv()

CSV_PATH = sys.argv[1] if len(sys.argv) > 1 else "produtos_2026-06-30-09-52-33.csv"
OUTPUT_PATH = sys.argv[2] if len(sys.argv) > 2 else "planilhas/produtos_otimizados.csv"

FTP_HOST = os.getenv("FTP_HOST")
FTP_USER = os.getenv("FTP_USER")
FTP_PASS = os.getenv("FTP_PASS")
BASE_URL = os.getenv("BASE_URL")

# =========================
# DOWNLOAD IMAGEM
# =========================
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

def coletar_urls_existentes(row):
    urls = []
    for col in [f"URL imagem {i}" for i in range(1, 11)] + [f"URL imagem externa {i}" for i in range(1, 11)]:
        valor = str(row.get(col) or "").strip()
        if valor:
            urls.append(valor)
    return list(dict.fromkeys([u for u in urls if u]))

def distribuir_urls(df_row, urls):
    cols = [f"URL imagem {i}" for i in range(1, 11)]
    for idx, col in enumerate(cols):
        df_row[col] = urls[idx] if idx < len(urls) else ""
    return df_row

def trim_text(text, limit):
    text = " ".join(str(text).split())
    if len(text) <= limit:
        return text
    cut = text[:limit].rsplit(" ", 1)[0]
    return cut.rstrip(" ,;:-")

def clean_slug(text):
    raw = str(text).lower().replace("/", " ").replace("*", " ").replace('"', "")
    raw = raw.replace("ç", "c").replace("á", "a").replace("é", "e").replace("í", "i").replace("ó", "o").replace("ú", "u")
    raw = raw.replace("ã", "a").replace("õ", "o").replace("ê", "e").replace("ô", "o").replace("à", "a")
    return "-".join([part for part in raw.split() if part])

def first_non_empty(row, columns, fallback=""):
    for col in columns:
        value = str(row.get(col) or "").strip()
        if value:
            return value
    return fallback

def strip_html(text):
    text = str(text or "")
    text = re.sub(r"<[^>]+>", " ", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip()

# =========================
# SEO
# =========================
def gerar_seo(nome, categoria="", marca="", descricao_base=""):
    texto = str(nome).strip()
    palavras = texto.lower().replace("/", " ").replace("*", " ").replace('"', "").split()
    stop = {"de", "para", "com", "e", "a", "o", "da", "do", "das", "dos", "em", "na", "no", "nas", "nos", "um", "uma"}
    palavras = [p for p in palavras if p not in stop]

    base = " ".join(palavras[:6]).strip()
    if not base:
        base = texto

    categoria_txt = str(categoria).strip()
    marca_txt = str(marca).strip()
    detalhe_base = strip_html(descricao_base)

    titulo_base = base.title()
    if marca_txt and marca_txt.lower() not in titulo_base.lower():
        titulo_base = f"{titulo_base} {marca_txt.title()}"
    if categoria_txt and categoria_txt.lower() not in titulo_base.lower() and len(titulo_base) < 58:
        titulo_base = f"{titulo_base} {categoria_txt.split('>')[0].strip().title()}"

    titulo = trim_text(f"{titulo_base} | ShopVivaliz", 70)

    detalhe = detalhe_base
    if detalhe:
        detalhe = trim_text(detalhe, 70)
        descricao = (
            f"{titulo_base}: {detalhe}. "
            f"Boa escolha para marketplace, com apresentação clara e foco em conversão."
        )
    else:
        descricao = (
            f"{titulo_base} com qualidade selecionada, ideal para marketplace e envio imediato. "
            f"Apresentação clara para destacar atributos e facilitar a compra."
        )
    descricao = trim_text(descricao, 160)

    slug = clean_slug(base)[:80].strip("-")
    slug = re.sub(r"-+", "-", slug).strip("-")

    keyword_parts = []
    for chunk in [base, marca_txt, categoria_txt.split(">")[0].strip() if categoria_txt else ""]:
        for part in clean_slug(chunk).split("-"):
            if part and part not in keyword_parts:
                keyword_parts.append(part)
    keywords = ", ".join(keyword_parts[:8])

    return titulo, descricao, slug, keywords

# =========================
# FTP UPLOAD
# =========================
def upload_imagens(imagens):
    if not (FTP_HOST and FTP_USER and FTP_PASS and BASE_URL):
        return [f"arquivo_local://{os.path.basename(img)}" for img in imagens]

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

# =========================
# PIPELINE
# =========================
def rodar_pipeline():

    df = pd.read_csv(CSV_PATH)
    df_final = df.copy()

    for i, row in df.iterrows():

        sku = str(row.get("Código (SKU)"))
        nome = first_non_empty(row, ["Descrição", "Título SEO", "Nome", "Código (SKU)"])
        categoria = first_non_empty(row, ["Categoria"])
        marca = first_non_empty(row, ["Marca"])
        descricao_base = first_non_empty(row, ["Descrição complementar", "Observações"])
        urls_existentes = coletar_urls_existentes(row)
        url = urls_existentes[0] if urls_existentes else ""

        print(f"Processando {sku}")

        titulo, descricao, slug, keywords = gerar_seo(nome, categoria, marca, descricao_base)

        imagens = []
        if url:
            img = baixar_imagem(url, sku)
            if img:
                imagens.append(img)

        produto_ia = {
            "sku": sku,
            "nome": nome,
            "imagem_original": imagens[0] if imagens else None,
        }

        if produto_ia["imagem_original"]:
            try:
                imagens.extend(gerar_imagens_ia(produto_ia))
            except Exception:
                pass
        urls = upload_imagens(imagens) if imagens else []

        # =========================
        # MERGE IMAGENS
        # =========================

        todas = list(dict.fromkeys(urls_existentes + urls))

        # =========================
        # ATUALIZAR
        # =========================

        if "Título SEO" in df_final.columns:
            df_final.loc[i, "Título SEO"] = titulo

        if "Descrição SEO" in df_final.columns:
            df_final.loc[i, "Descrição SEO"] = descricao

        if "Slug" in df_final.columns:
            df_final.loc[i, "Slug"] = slug

        if "Palavras chave SEO" in df_final.columns:
            df_final.loc[i, "Palavras chave SEO"] = keywords

        df_final.loc[i] = distribuir_urls(df_final.loc[i].copy(), todas)

        print("OK")

    os.makedirs(os.path.dirname(OUTPUT_PATH) or ".", exist_ok=True)
    df_final.to_csv(OUTPUT_PATH, index=False, encoding="utf-8-sig")
    print(f"\nArquivo final gerado em: {OUTPUT_PATH}")

if __name__ == "__main__":
    rodar_pipeline()
