#!/usr/bin/env python3
"""
ShopVivaliz - Gerador de Imagens IA
Etapa 3 do pipeline: analisa imagens reais de produto via GPT-4o Vision
e gera 4 variações com DALL-E 3 (fundo branco, 45°, lifestyle, close-up).
"""

from __future__ import annotations

import argparse
import csv
import ftplib
import hashlib
import json
import os
import posixpath
import re
import sys
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen

# ---------------------------------------------------------------------------
# Constantes
# ---------------------------------------------------------------------------

OPENAI_CHAT_URL    = "https://api.openai.com/v1/chat/completions"
OPENAI_IMAGE_URL   = "https://api.openai.com/v1/images/generations"
VISION_MODEL       = os.getenv("OPENAI_VISION_MODEL",  "gpt-4o")
GENERATION_MODEL   = os.getenv("OPENAI_IMAGE_MODEL",   "dall-e-3")
IMAGE_SIZE         = os.getenv("OPENAI_IMAGE_SIZE",     "1024x1024")
IMAGE_QUALITY      = os.getenv("OPENAI_IMAGE_QUALITY",  "hd")
DEFAULT_IN_CSV     = Path(os.getenv("AI_INPUT_CSV",     "logs/olist-images-export.csv"))
DEFAULT_OUT_DIR    = Path(os.getenv("AI_IMAGES_DIR",    "storage/ai-images"))
DEFAULT_REPORTS    = Path(os.getenv("AI_REPORTS_DIR",   "logs"))
REMOTE_ROOT        = os.getenv("AI_REMOTE_ROOT",        "uploads/ai-images")
SITE_BASE_URL      = os.getenv("SITE_BASE_URL",         "https://dev.shopvivaliz.com.br")
USER_AGENT         = "ShopVivalizAIImageGen/1.0"
MAX_RETRY          = int(os.getenv("AI_MAX_RETRY",      "2"))
SLEEP_BETWEEN      = float(os.getenv("AI_SLEEP_BETWEEN","2.0"))

IMAGE_TYPES = ["fundo_branco", "angulo_45", "lifestyle", "close_up"]

# Prompts base para cada tipo de imagem
PROMPT_TEMPLATES: Dict[str, str] = {
    "fundo_branco": (
        "Professional e-commerce product photo: {product_desc}. "
        "Pure white background (#FFFFFF), centered composition, even soft studio lighting, "
        "sharp focus on entire product, no shadows, no props, no text, no watermarks. "
        "High resolution, marketplace ready (Shopee standard)."
    ),
    "angulo_45": (
        "E-commerce product photo at 45-degree angle: {product_desc}. "
        "White or light grey background, dramatic 3/4 perspective showing depth and texture, "
        "professional studio lighting, sharp focus, no text, no watermarks."
    ),
    "lifestyle": (
        "Lifestyle product photo: {product_desc}. "
        "{audience_context}. Natural ambient light, aspirational real-life setting, "
        "product as hero with complementary minimal props, warm color palette, "
        "no text overlays, no watermarks, cinematic quality."
    ),
    "close_up": (
        "Extreme close-up macro product photo: {product_desc}. "
        "Focus on texture, material quality and finest details. "
        "White or neutral background, macro lens effect, razor-sharp focus, "
        "professional lighting that reveals material quality, no text, no watermarks."
    ),
}

# Contextos de público por categoria
AUDIENCE_CONTEXTS: Dict[str, str] = {
    "moda":        "worn by a stylish young adult in an urban setting",
    "casa":        "arranged in a cozy modern home interior",
    "beleza":      "placed on a clean marble vanity with natural light",
    "eletronicos": "on a modern desk workspace with minimal accessories",
    "esportes":    "in an active outdoor setting with natural light",
    "brinquedos":  "in a bright playful children's space",
    "alimentos":   "in a fresh kitchen setting with natural ingredients",
    "default":     "in an aspirational lifestyle setting with complementary elements",
}


# ---------------------------------------------------------------------------
# Estruturas de dados
# ---------------------------------------------------------------------------

@dataclass
class ProductRow:
    olist_id:          str
    sku:               str
    nome:              str
    primary_image_url: str
    category_hint:     str = ""
    all_image_urls:    str = ""


@dataclass
class GeneratedImage:
    product_sku:      str
    olist_id:         str
    image_type:       str
    prompt:           str
    openai_url:       str   = ""
    local_file:       str   = ""
    site_url:         str   = ""
    uploaded:         bool  = False
    status:           str   = "pending"
    error_message:    str   = ""
    revised_prompt:   str   = ""


@dataclass
class ProductAnalysis:
    product_desc:    str
    category:        str
    color:           str
    material:        str
    size:            str
    audience:        str
    audience_context:str
    raw_response:    str


# ---------------------------------------------------------------------------
# Utilitários
# ---------------------------------------------------------------------------

def log(msg: str) -> None:
    print(msg, flush=True)


def fail(msg: str, code: int = 1) -> None:
    print(f"ERRO: {msg}", file=sys.stderr, flush=True)
    sys.exit(code)


def sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def openai_key(dry_run: bool = False) -> str:
    key = os.getenv("OPENAI_API_KEY", "").strip()
    if not key and not dry_run:
        fail("OPENAI_API_KEY não configurada. Adicione nos secrets do GitHub ou no .env.")
    return key


def http_json_post(url: str, payload: dict, api_key: str, timeout: int = 120) -> dict:
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req  = Request(
        url,
        data=body,
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type":  "application/json",
            "User-Agent":    USER_AGENT,
        },
        method="POST",
    )
    try:
        with urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
            return json.loads(raw) if raw else {}
    except HTTPError as exc:
        body_err = exc.read().decode("utf-8", errors="replace")
        return {"_http_status": exc.code, "_error": body_err[:1000]}
    except (URLError, TimeoutError) as exc:
        return {"_http_status": 0, "_error": str(exc)}
    except json.JSONDecodeError as exc:
        return {"_http_status": 0, "_error": f"invalid_json:{exc}"}


def download_bytes(url: str, timeout: int = 60) -> Tuple[bytes, str]:
    req = Request(url, headers={"User-Agent": USER_AGENT}, method="GET")
    with urlopen(req, timeout=timeout) as resp:
        content_type = resp.headers.get("Content-Type", "image/jpeg")
        return resp.read(), content_type


def ext_from_content_type(ct: str) -> str:
    ct = (ct or "").split(";")[0].strip().lower()
    mapping = {
        "image/jpeg": ".jpg", "image/jpg": ".jpg",
        "image/png": ".png", "image/webp": ".webp",
        "image/gif": ".gif",
    }
    return mapping.get(ct, ".jpg")


# ---------------------------------------------------------------------------
# Leitura do CSV de entrada
# ---------------------------------------------------------------------------

def load_products(csv_path: Path, limit: int = 0) -> List[ProductRow]:
    if not csv_path.is_file():
        fail(f"CSV de entrada não encontrado: {csv_path}")
    raw      = csv_path.read_text(encoding="utf-8-sig", errors="replace")
    reader   = csv.DictReader(raw.splitlines())
    products: List[ProductRow] = []
    for row in reader:
        url = (row.get("primary_image_url") or "").strip()
        if not url or not url.startswith("http"):
            continue
        products.append(ProductRow(
            olist_id=         (row.get("olist_id") or "").strip(),
            sku=              (row.get("sku") or "").strip(),
            nome=             (row.get("nome") or "").strip(),
            primary_image_url=url,
            all_image_urls=   (row.get("all_image_urls") or "").strip(),
        ))
        if limit > 0 and len(products) >= limit:
            break
    return products


# ---------------------------------------------------------------------------
# Análise de imagem com GPT-4o Vision
# ---------------------------------------------------------------------------

VISION_PROMPT = """Analise a imagem deste produto de e-commerce e retorne um JSON com estes campos:
{
  "product_desc": "descrição detalhada do produto em inglês para prompt de geração de imagem",
  "category": "categoria do produto em português (ex: moda, casa, beleza, eletronicos, esportes, brinquedos, alimentos)",
  "color": "cor principal do produto",
  "material": "material principal do produto",
  "size": "tamanho ou dimensão estimada",
  "audience": "público-alvo em português (ex: mulheres jovens, crianças, profissionais)",
  "style_notes": "notas de estilo visual para manter fidelidade na geração"
}

Responda APENAS o JSON, sem markdown, sem explicações."""


def analyze_product_image(image_url: str, api_key: str, product_name: str) -> ProductAnalysis:
    payload = {
        "model": VISION_MODEL,
        "max_tokens": 500,
        "messages": [
            {
                "role": "user",
                "content": [
                    {
                        "type":      "image_url",
                        "image_url": {"url": image_url, "detail": "high"},
                    },
                    {
                        "type": "text",
                        "text": f"Nome do produto: {product_name}\n\n{VISION_PROMPT}",
                    },
                ],
            }
        ],
    }

    for attempt in range(MAX_RETRY + 1):
        result = http_json_post(OPENAI_CHAT_URL, payload, api_key, timeout=90)
        if result.get("_http_status"):
            if attempt < MAX_RETRY:
                time.sleep(3)
                continue
            return ProductAnalysis(
                product_desc=product_name,
                category="default",
                color="",
                material="",
                size="",
                audience="",
                audience_context=AUDIENCE_CONTEXTS["default"],
                raw_response=result.get("_error", "")[:500],
            )
        break

    raw_text = ""
    try:
        raw_text = result["choices"][0]["message"]["content"]
        data     = json.loads(raw_text)
    except (KeyError, IndexError, json.JSONDecodeError):
        data = {}

    category = (data.get("category") or "default").lower()
    cat_key  = next((k for k in AUDIENCE_CONTEXTS if k in category), "default")

    return ProductAnalysis(
        product_desc=    data.get("product_desc")  or product_name,
        category=        category,
        color=           data.get("color")          or "",
        material=        data.get("material")       or "",
        size=            data.get("size")           or "",
        audience=        data.get("audience")       or "",
        audience_context=AUDIENCE_CONTEXTS[cat_key],
        raw_response=    raw_text[:1000],
    )


# ---------------------------------------------------------------------------
# Geração de prompts
# ---------------------------------------------------------------------------

def build_prompt(image_type: str, analysis: ProductAnalysis) -> str:
    template = PROMPT_TEMPLATES[image_type]
    return template.format(
        product_desc=    analysis.product_desc,
        audience_context=analysis.audience_context,
    )


# ---------------------------------------------------------------------------
# Geração de imagem com DALL-E 3
# ---------------------------------------------------------------------------

def generate_image(prompt: str, api_key: str) -> Tuple[str, str, str]:
    """Retorna (openai_url, revised_prompt, error_message)."""
    payload = {
        "model":   GENERATION_MODEL,
        "prompt":  prompt,
        "n":       1,
        "size":    IMAGE_SIZE,
        "quality": IMAGE_QUALITY,
    }

    for attempt in range(MAX_RETRY + 1):
        result = http_json_post(OPENAI_IMAGE_URL, payload, api_key, timeout=120)
        if result.get("_http_status"):
            err = result.get("_error", "unknown error")[:500]
            if attempt < MAX_RETRY:
                log(f"    Tentativa {attempt+1} falhou: {err[:80]}. Aguardando...")
                time.sleep(5)
                continue
            return "", "", err
        break

    try:
        item          = result["data"][0]
        url           = item.get("url", "")
        revised       = item.get("revised_prompt", "")
        return url, revised, ""
    except (KeyError, IndexError) as exc:
        return "", "", f"resposta inesperada: {exc}"


# ---------------------------------------------------------------------------
# FTP Upload
# ---------------------------------------------------------------------------

class FtpUploader:
    def __init__(self, remote_root: str) -> None:
        server   = os.getenv("FTP_SERVER",   "").strip()
        username = os.getenv("FTP_USERNAME", "").strip()
        password = os.getenv("FTP_PASSWORD", "").strip()
        if not server or not username:
            raise RuntimeError("FTP_SERVER ou FTP_USERNAME ausente — upload via FTP desativado.")
        port           = int(os.getenv("FTP_PORT", "21") or "21")
        self.base_dir  = (os.getenv("FTP_REMOTE_DIR") or "/").strip() or "/"
        self.remote_root = remote_root.strip("/")
        self.ftp       = ftplib.FTP()
        self.ftp.connect(server, port, timeout=60)
        self.ftp.login(username, password)

    def _ensure_dir(self, path: str) -> None:
        parts = [p for p in path.replace("\\", "/").split("/") if p]
        if path.startswith("/"):
            self.ftp.cwd("/")
        for part in parts:
            try:
                self.ftp.mkd(part)
            except ftplib.error_perm:
                pass
            self.ftp.cwd(part)

    def upload(self, local_file: Path, sku_key: str, image_type: str) -> Tuple[bool, str, str]:
        remote_dir = posixpath.join(
            self.base_dir, self.remote_root, sku_key
        ).replace("\\", "/")
        try:
            self._ensure_dir(remote_dir)
            remote_name = f"{image_type}_{local_file.name}"
            try:
                exists = self.ftp.size(remote_name) is not None
            except ftplib.error_perm:
                exists = False
            if not exists:
                with local_file.open("rb") as fh:
                    self.ftp.storbinary(f"STOR {remote_name}", fh)
            site_path = "/".join([
                self.remote_root,
                quote(sku_key),
                quote(remote_name),
            ])
            return True, f"{SITE_BASE_URL}/{site_path}", ""
        except Exception as exc:
            return False, "", f"FTP falhou: {exc.__class__.__name__}: {exc}"

    def close(self) -> None:
        try:
            self.ftp.quit()
        except Exception:
            pass


def build_uploader() -> Optional[FtpUploader]:
    if os.getenv("FTP_SERVER") and os.getenv("FTP_USERNAME") and os.getenv("FTP_PASSWORD"):
        try:
            return FtpUploader(REMOTE_ROOT)
        except Exception as exc:
            log(f"  Aviso FTP: {exc}. Imagens salvas apenas localmente.")
    return None


# ---------------------------------------------------------------------------
# Salvamento local
# ---------------------------------------------------------------------------

def save_image_locally(
    image_bytes: bytes,
    content_type: str,
    out_dir: Path,
    sku_key: str,
    image_type: str,
    url_hash: str,
) -> Path:
    dest_dir = out_dir / sku_key
    dest_dir.mkdir(parents=True, exist_ok=True)
    ext  = ext_from_content_type(content_type)
    name = f"{image_type}_{url_hash[:12]}{ext}"
    path = dest_dir / name
    path.write_bytes(image_bytes)
    return path


def sanitize_key(value: str, fallback: str) -> str:
    text = re.sub(r"[\\/:*?\"<>|]+", "-", (value or fallback).strip())
    text = re.sub(r"\s+", "-", text).strip(".- ")
    return text[:80] or fallback


# ---------------------------------------------------------------------------
# Geração completa por produto
# ---------------------------------------------------------------------------

def process_product(
    product:  ProductRow,
    api_key:  str,
    out_dir:  Path,
    uploader: Optional[FtpUploader],
    dry_run:  bool,
) -> List[GeneratedImage]:

    sku_key = sanitize_key(product.sku or product.olist_id, f"olist-{product.olist_id or 'sem-id'}")
    results: List[GeneratedImage] = []

    # 1. Análise visual da imagem real do produto
    log(f"  Analisando imagem com {VISION_MODEL}: {product.primary_image_url[:60]}...")
    analysis = analyze_product_image(product.primary_image_url, api_key, product.nome)
    log(f"    Categoria: {analysis.category} | Produto: {analysis.product_desc[:60]}")

    # 2. Gerar 4 variações
    for image_type in IMAGE_TYPES:
        prompt = build_prompt(image_type, analysis)
        img    = GeneratedImage(
            product_sku=product.sku,
            olist_id=   product.olist_id,
            image_type= image_type,
            prompt=     prompt,
        )

        if dry_run:
            img.status        = "dry_run"
            img.error_message = "dry_run ativo — nenhuma geração executada"
            results.append(img)
            continue

        log(f"  [{image_type}] Gerando com {GENERATION_MODEL}...")
        openai_url, revised, err = generate_image(prompt, api_key)

        if err or not openai_url:
            img.status        = "error"
            img.error_message = err or "url vazia retornada pela API"
            results.append(img)
            log(f"    Erro: {img.error_message[:80]}")
            continue

        img.openai_url    = openai_url
        img.revised_prompt = revised

        # 3. Download da imagem gerada (URLs do DALL-E expiram)
        try:
            image_bytes, content_type = download_bytes(openai_url, timeout=90)
        except Exception as exc:
            img.status        = "error"
            img.error_message = f"download falhou: {exc}"
            results.append(img)
            log(f"    Download falhou: {exc}")
            continue

        url_hash       = sha256_text(openai_url)
        local_path     = save_image_locally(image_bytes, content_type, out_dir, sku_key, image_type, url_hash)
        img.local_file = str(local_path)
        log(f"    Salvo: {local_path.name}")

        # 4. Upload FTP
        if uploader:
            uploaded, site_url, ftp_err = uploader.upload(local_path, sku_key, image_type)
            img.uploaded  = uploaded
            img.site_url  = site_url
            if ftp_err:
                img.error_message = ftp_err
                img.status        = "uploaded_locally"
                log(f"    FTP: {ftp_err[:60]}")
            else:
                img.status = "uploaded"
                log(f"    FTP OK: {site_url[:70]}")
        else:
            img.status = "generated"

        results.append(img)
        time.sleep(1.0)  # respeitar rate limit da API

    return results


# ---------------------------------------------------------------------------
# Relatório CSV / JSON
# ---------------------------------------------------------------------------

REPORT_FIELDS = [
    "product_sku", "olist_id", "image_type", "prompt",
    "openai_url", "local_file", "site_url",
    "uploaded", "status", "error_message", "revised_prompt",
]


def write_csv(rows: List[GeneratedImage], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8-sig") as fh:
        writer = csv.DictWriter(fh, fieldnames=REPORT_FIELDS)
        writer.writeheader()
        for r in rows:
            writer.writerow({f: getattr(r, f) for f in REPORT_FIELDS})


def write_json_report(
    rows:     List[GeneratedImage],
    products: List[ProductRow],
    path:     Path,
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)

    by_sku: Dict[str, Dict[str, Any]] = {}
    for r in rows:
        key = r.product_sku or r.olist_id
        if key not in by_sku:
            by_sku[key] = {
                "sku":         r.product_sku,
                "olist_id":    r.olist_id,
                "images":      {},
                "has_error":   False,
            }
        by_sku[key]["images"][r.image_type] = {
            "site_url":   r.site_url,
            "local_file": r.local_file,
            "status":     r.status,
            "error":      r.error_message,
        }
        if r.status in ("error", "uploaded_locally"):
            by_sku[key]["has_error"] = True

    summary = {
        "total_products":  len(products),
        "total_images":    len(rows),
        "uploaded":        sum(1 for r in rows if r.status == "uploaded"),
        "generated_only":  sum(1 for r in rows if r.status == "generated"),
        "errors":          sum(1 for r in rows if r.status == "error"),
    }

    data = {
        "ok":          True,
        "agent":       "shopvivaliz_ai_image_generator",
        "generated_at":datetime.now(timezone.utc).isoformat(),
        "vision_model":VISION_MODEL,
        "image_model": GENERATION_MODEL,
        "summary":     summary,
        "products":    list(by_sku.values()),
    }
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


# ---------------------------------------------------------------------------
# Args & main
# ---------------------------------------------------------------------------

def parse_args(argv: List[str]) -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Gerador de imagens IA para ShopVivaliz.")
    p.add_argument("--input",      default=str(DEFAULT_IN_CSV),  help="CSV de produtos Olist exportado.")
    p.add_argument("--out-dir",    default=str(DEFAULT_OUT_DIR), help="Pasta local para salvar imagens geradas.")
    p.add_argument("--reports-dir",default=str(DEFAULT_REPORTS), help="Pasta para relatórios CSV/JSON.")
    p.add_argument("--limit",      type=int, default=int(os.getenv("AI_LIMIT", "0")),
                   help="Limite de produtos (0 = todos).")
    p.add_argument("--dry-run",    action="store_true",           help="Não gera imagens; apenas simula.")
    p.add_argument("--skip-upload",action="store_true",           help="Não faz upload FTP.")
    p.add_argument("--types",      nargs="+", choices=IMAGE_TYPES, default=IMAGE_TYPES,
                   help="Tipos de imagem a gerar.")
    return p.parse_args(argv)


def main(argv: List[str]) -> int:
    args     = parse_args(argv)
    api_key  = openai_key(dry_run=args.dry_run)
    in_path  = Path(args.input)
    out_dir  = Path(args.out_dir)
    rep_dir  = Path(args.reports_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    rep_dir.mkdir(parents=True, exist_ok=True)

    # Filtrar tipos solicitados
    global IMAGE_TYPES
    IMAGE_TYPES = args.types

    log("=== ShopVivaliz AI Image Generator ===")
    log(f"Vision: {VISION_MODEL} | Gerador: {GENERATION_MODEL} | Tamanho: {IMAGE_SIZE}")

    products = load_products(in_path, limit=args.limit)
    log(f"Produtos com imagem: {len(products)}")

    if not products:
        log("Nenhum produto com imagem encontrada. Verifique o CSV de entrada.")
        return 0

    uploader: Optional[FtpUploader] = None
    if not args.skip_upload and not args.dry_run:
        uploader = build_uploader()

    all_images: List[GeneratedImage] = []
    for idx, product in enumerate(products, start=1):
        log(f"\n[{idx}/{len(products)}] SKU={product.sku or '-'} | {product.nome[:50]}")
        images = process_product(product, api_key, out_dir, uploader, args.dry_run)
        all_images.extend(images)
        time.sleep(SLEEP_BETWEEN)

    if uploader:
        uploader.close()

    # Relatórios
    csv_path  = rep_dir / "ai-images-report.csv"
    json_path = rep_dir / "ai-images-report.json"
    write_csv(all_images, csv_path)
    write_json_report(all_images, products, json_path)

    uploaded = sum(1 for r in all_images if r.status == "uploaded")
    errors   = sum(1 for r in all_images if r.status == "error")
    log(f"\n=== CONCLUÍDO ===")
    log(f"Produtos processados: {len(products)}")
    log(f"Imagens geradas:      {len(all_images)}")
    log(f"Enviadas (FTP):       {uploaded}")
    log(f"Erros:                {errors}")
    log(f"CSV: {csv_path}")
    log(f"JSON: {json_path}")
    return 0 if errors == 0 else 1


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv[1:]))
    except KeyboardInterrupt:
        print("\nInterrompido.", file=sys.stderr)
        raise SystemExit(130)
