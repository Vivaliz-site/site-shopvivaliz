#!/usr/bin/env python3
"""Sync Olist/Tiny ERP product images into ShopVivaliz.

The script is intentionally conservative:
- it never prints tokens or credentials;
- it never deletes existing site or downloaded images;
- it uses stable URL/file hashes to avoid duplicates on reruns;
- it links by SKU first, then by Olist/Tiny product id.
"""

from __future__ import annotations

import argparse
import csv
import ftplib
import hashlib
import json
import mimetypes
import os
import posixpath
import re
import shutil
import subprocess
import sys
import time
import zipfile
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Sequence, Tuple
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode, urlparse
from urllib.request import Request, urlopen
import xml.etree.ElementTree as ET


DEFAULT_API_BASE = "https://api.tiny.com.br/public-api/v3"
DEFAULT_API_V2_BASE = "https://api.tiny.com.br/api2"
DEFAULT_TOKEN_URL = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"
DEFAULT_SITE_BASE_URL = "https://dev.shopvivaliz.com.br"
DEFAULT_STORAGE_IMAGES = Path("storage/olist-images")
DEFAULT_REPORTS_DIR = Path("storage/reports")
DEFAULT_REMOTE_UPLOAD_ROOT = "uploads/olist"
USER_AGENT = "ShopVivalizOlistImageSync/1.0"
IMAGE_URL_RE = re.compile(r"https?://[^\s\"'<>|,;]+", re.IGNORECASE)
IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp", ".gif", ".bmp", ".avif"}


@dataclass
class ProductInput:
    sku: str
    olist_product_id: str
    nome_produto: str
    sheet_image_urls: List[str] = field(default_factory=list)
    source_row: int = 0


@dataclass
class DownloadedImage:
    sku: str
    sku_key: str
    olist_product_id: str
    nome_produto: str
    image_position: int
    original_url_olist: str
    local_file: str = ""
    url_hash: str = ""
    file_hash: str = ""
    downloaded: bool = False
    status: str = "pending"
    error_message: str = ""


@dataclass
class FinalImageRow:
    sku: str
    olist_product_id: str
    product_local_id: str
    nome_produto: str
    image_position: int
    original_url_olist: str
    local_file: str
    site_url: str
    uploaded: bool
    linked: bool
    is_primary: bool
    status: str
    error_message: str
    url_hash: str
    file_hash: str
    dedupe_key: str


class SafeError(RuntimeError):
    """An operational error that is safe to show to the user."""


def log(message: str) -> None:
    print(message, flush=True)


def normalize_header(value: str) -> str:
    value = (value or "").strip().lower()
    replacements = {
        "á": "a",
        "à": "a",
        "â": "a",
        "ã": "a",
        "ä": "a",
        "é": "e",
        "ê": "e",
        "ë": "e",
        "í": "i",
        "ï": "i",
        "ó": "o",
        "ô": "o",
        "õ": "o",
        "ö": "o",
        "ú": "u",
        "ü": "u",
        "ç": "c",
    }
    for old, new in replacements.items():
        value = value.replace(old, new)
    value = re.sub(r"[^a-z0-9]+", "_", value).strip("_")
    return value


def clean_cell(value: Any) -> str:
    if value is None:
        return ""
    text = str(value).replace("\r", " ").replace("\n", " ").strip()
    return re.sub(r"\s+", " ", text)


def sanitize_path_part(value: str, fallback: str) -> str:
    text = clean_cell(value) or fallback
    text = re.sub(r"[\\/:*?\"<>|]+", "-", text)
    text = re.sub(r"\s+", "-", text).strip(".- ")
    if re.match(r"^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?$", text, re.IGNORECASE):
        text = "sku-" + text
    return text[:120] or fallback


def sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def normalize_url_for_hash(url: str) -> str:
    return clean_cell(url)


def extract_http_image_urls(text: str) -> List[str]:
    urls: List[str] = []
    for match in IMAGE_URL_RE.findall(text or ""):
        candidate = match.rstrip(").]}")
        parsed = urlparse(candidate)
        suffix = Path(parsed.path).suffix.lower()
        if suffix in IMAGE_EXTENSIONS or any(k in candidate.lower() for k in ("imagem", "image", "foto", "photo", "cdn")):
            if candidate not in urls:
                urls.append(candidate)
    return urls


def first_existing(mapping: Dict[int, str], indexes: Sequence[int]) -> str:
    for idx in indexes:
        value = clean_cell(mapping.get(idx, ""))
        if value:
            return value
    return ""


def detect_columns(headers: Dict[int, str]) -> Tuple[List[int], List[int], List[int], List[int]]:
    sku_cols: List[int] = []
    id_cols: List[int] = []
    name_cols: List[int] = []
    image_cols: List[int] = []

    for idx, header in headers.items():
        if re.search(r"(^|_)(sku|codigo_sku|codigo|referencia|ref)(_|$)", header):
            sku_cols.append(idx)
        if re.search(r"(^|_)(olist_product_id|idproduto|id_produto|codigo_produto|produto_id|product_id|id)(_|$)", header):
            id_cols.append(idx)
        if re.search(r"(^|_)(nome_produto|nome|descricao|produto|titulo|title|name)(_|$)", header):
            name_cols.append(idx)
        if re.search(r"(imagem|imagens|image|images|foto|fotos|photo|url|link|midia|media)", header):
            image_cols.append(idx)

    return sku_cols, id_cols, name_cols, image_cols


def rows_to_products(raw_rows: List[List[str]]) -> List[ProductInput]:
    if not raw_rows:
        return []

    header_row = raw_rows[0]
    headers = {idx: normalize_header(value) for idx, value in enumerate(header_row)}
    sku_cols, id_cols, name_cols, image_cols = detect_columns(headers)

    if not sku_cols and not id_cols:
        raise SafeError("A planilha/CSV precisa ter coluna de SKU/codigo ou ID do produto Olist/Tiny.")

    by_key: Dict[str, ProductInput] = {}
    for row_number, row_values in enumerate(raw_rows[1:], start=2):
        row = {idx: clean_cell(value) for idx, value in enumerate(row_values)}
        sku = first_existing(row, sku_cols)
        product_id = re.sub(r"\D+", "", first_existing(row, id_cols))
        name = first_existing(row, name_cols)

        urls: List[str] = []
        scan_cols = image_cols or list(row.keys())
        for col in scan_cols:
            for url in extract_http_image_urls(row.get(col, "")):
                if url not in urls:
                    urls.append(url)

        if not sku and not product_id and not name and not urls:
            continue

        key = f"sku:{sku.upper()}" if sku else f"id:{product_id}"
        if key not in by_key:
            by_key[key] = ProductInput(
                sku=sku,
                olist_product_id=product_id,
                nome_produto=name,
                sheet_image_urls=[],
                source_row=row_number,
            )
        item = by_key[key]
        if not item.nome_produto and name:
            item.nome_produto = name
        if not item.olist_product_id and product_id:
            item.olist_product_id = product_id
        for url in urls:
            if url not in item.sheet_image_urls:
                item.sheet_image_urls.append(url)

    return list(by_key.values())


def read_csv_rows(path: Path) -> List[List[str]]:
    raw = path.read_text(encoding="utf-8-sig", errors="replace")
    sample = raw[:4096]
    try:
        dialect = csv.Sniffer().sniff(sample, delimiters=",;\t|")
    except csv.Error:
        dialect = csv.excel
        if sample.count(";") > sample.count(","):
            dialect.delimiter = ";"
    reader = csv.reader(raw.splitlines(), dialect)
    return [[clean_cell(cell) for cell in row] for row in reader]


def xlsx_column_index(cell_ref: str) -> int:
    match = re.match(r"([A-Z]+)", cell_ref.upper())
    if not match:
        return 0
    number = 0
    for char in match.group(1):
        number = number * 26 + (ord(char) - 64)
    return number - 1


def xml_text(element: Optional[ET.Element]) -> str:
    if element is None:
        return ""
    return "".join(element.itertext())


def read_xlsx_rows(path: Path) -> List[List[str]]:
    namespaces = {
        "main": "http://schemas.openxmlformats.org/spreadsheetml/2006/main",
    }
    with zipfile.ZipFile(path) as archive:
        shared: List[str] = []
        if "xl/sharedStrings.xml" in archive.namelist():
            root = ET.fromstring(archive.read("xl/sharedStrings.xml"))
            for si in root.findall("main:si", namespaces):
                shared.append(xml_text(si))

        worksheet_names = sorted(
            name for name in archive.namelist() if re.match(r"xl/worksheets/sheet\d+\.xml$", name)
        )
        if not worksheet_names:
            raise SafeError("Nenhuma planilha XLSX encontrada dentro do arquivo.")

        sheet = ET.fromstring(archive.read(worksheet_names[0]))
        rows: List[List[str]] = []
        for row in sheet.findall(".//main:sheetData/main:row", namespaces):
            values: Dict[int, str] = {}
            max_index = -1
            for cell in row.findall("main:c", namespaces):
                ref = cell.attrib.get("r", "")
                idx = xlsx_column_index(ref)
                max_index = max(max_index, idx)
                cell_type = cell.attrib.get("t", "")
                value = ""
                if cell_type == "s":
                    raw = xml_text(cell.find("main:v", namespaces))
                    value = shared[int(raw)] if raw.isdigit() and int(raw) < len(shared) else ""
                elif cell_type == "inlineStr":
                    value = xml_text(cell.find("main:is", namespaces))
                else:
                    value = xml_text(cell.find("main:v", namespaces))
                values[idx] = clean_cell(value)

            if max_index >= 0:
                rows.append([values.get(i, "") for i in range(max_index + 1)])

    return rows


def read_input_products(path: Path) -> List[ProductInput]:
    suffix = path.suffix.lower()
    if suffix == ".xlsx":
        rows = read_xlsx_rows(path)
    elif suffix in {".csv", ".txt"}:
        rows = read_csv_rows(path)
    else:
        raise SafeError("Formato de entrada nao suportado. Use .xlsx ou .csv.")
    products = rows_to_products(rows)
    if not products:
        raise SafeError("Nenhum produto encontrado na planilha/CSV.")
    return products


def write_input_csv(products: Sequence[ProductInput], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8-sig") as fh:
        writer = csv.DictWriter(fh, fieldnames=["sku", "olist_product_id", "nome_produto"])
        writer.writeheader()
        for item in products:
            writer.writerow(
                {
                    "sku": item.sku,
                    "olist_product_id": item.olist_product_id,
                    "nome_produto": item.nome_produto,
                }
            )


def http_json(request: Request, timeout: int = 45) -> Dict[str, Any]:
    try:
        with urlopen(request, timeout=timeout) as response:
            body = response.read().decode("utf-8", errors="replace")
            return json.loads(body) if body else {}
    except HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        return {"_http_status": exc.code, "_body": body[:1000]}
    except (URLError, TimeoutError) as exc:
        return {"_http_status": 0, "_error": str(exc)}
    except json.JSONDecodeError as exc:
        return {"_http_status": 0, "_error": f"invalid_json: {exc}"}


def http_post_form(url: str, data: Dict[str, str], timeout: int = 45) -> Dict[str, Any]:
    request = Request(
        url,
        data=urlencode(data).encode("utf-8"),
        headers={"Content-Type": "application/x-www-form-urlencoded", "User-Agent": USER_AGENT},
        method="POST",
    )
    return http_json(request, timeout=timeout)


def resolve_access_token(args: argparse.Namespace) -> Optional[str]:
    if args.api_version == "v2":
        for name in ("TINY_API_TOKEN", "TOKEN_API_OLIST", "OLIST_API_TOKEN"):
            value = os.getenv(name)
            if value:
                log(f"Secret configurado: {name}")
                return value
        raise SafeError("Credenciais V2 ausentes: TINY_API_TOKEN, TOKEN_API_OLIST ou OLIST_API_TOKEN.")

    for name in (
        "OLIST_ACCESS_TOKEN",
        "TINY_ACCESS_TOKEN",
        "ERP_API_TOKEN",
        "TOKEN_API_OLIST",
        "OLIST_API_TOKEN",
        "TINY_API_TOKEN",
    ):
        value = os.getenv(name)
        if value:
            log(f"Secret configurado: {name}")
            return value

    client_id = os.getenv("OLIST_CLIENT_ID") or os.getenv("TINY_CLIENT_ID") or os.getenv("CLIENT_ID_API_OLIST")
    client_secret = os.getenv("OLIST_CLIENT_SECRET") or os.getenv("TINY_CLIENT_SECRET") or os.getenv("CLIENT_SECRET_OLIST")
    refresh_token = os.getenv("OLIST_REFRESH_TOKEN") or os.getenv("TINY_REFRESH_TOKEN")
    if client_id and client_secret and refresh_token:
        log("Renovando access token via OAuth refresh_token.")
        response = http_post_form(
            args.token_url,
            {
                "grant_type": "refresh_token",
                "client_id": client_id,
                "client_secret": client_secret,
                "refresh_token": refresh_token,
            },
        )
        token = response.get("access_token")
        if token:
            return str(token)
        raise SafeError("Falha ao renovar token OAuth. Verifique OLIST/TINY refresh token sem expor valores.")

    missing = [
        "OLIST_ACCESS_TOKEN ou TINY_ACCESS_TOKEN",
        "ou OLIST_CLIENT_ID/TINY_CLIENT_ID + OLIST_CLIENT_SECRET/TINY_CLIENT_SECRET + OLIST_REFRESH_TOKEN/TINY_REFRESH_TOKEN",
    ]
    raise SafeError("Credenciais ausentes: " + "; ".join(missing))


def api_get(args: argparse.Namespace, access_token: str, path: str, params: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
    query = f"?{urlencode(params)}" if params else ""
    url = args.api_base.rstrip("/") + path + query
    request = Request(
        url,
        headers={
            "Authorization": f"Bearer {access_token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
            "User-Agent": USER_AGENT,
        },
        method="GET",
    )
    return http_json(request, timeout=args.api_timeout)


def api_get_v2(args: argparse.Namespace, token: str, endpoint: str, params: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
    merged = {"token": token, "formato": "json"}
    if params:
        merged.update(params)
    query = urlencode(merged)
    url = args.api_v2_base.rstrip("/") + "/" + endpoint.lstrip("/") + "?" + query
    request = Request(
        url,
        headers={
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
        },
        method="GET",
    )
    return http_json(request, timeout=args.api_timeout)


def unwrap_product(payload: Dict[str, Any]) -> Dict[str, Any]:
    if not isinstance(payload, dict):
        return {}
    for key in ("produto", "data", "item"):
        value = payload.get(key)
        if isinstance(value, dict):
            return value
    if "id" in payload or "sku" in payload or "nome" in payload:
        return payload
    return payload


def unwrap_product_v2(payload: Dict[str, Any]) -> Dict[str, Any]:
    retorno = payload.get("retorno")
    if isinstance(retorno, dict):
        produto = retorno.get("produto")
        if isinstance(produto, dict):
            return produto
    return {}


def extract_items(payload: Dict[str, Any]) -> List[Dict[str, Any]]:
    for key in ("itens", "items", "produtos", "products", "data"):
        value = payload.get(key)
        if isinstance(value, list):
            return [item for item in value if isinstance(item, dict)]
    retorno = payload.get("retorno")
    if isinstance(retorno, dict):
        for key in ("produtos", "items", "data"):
            value = retorno.get(key)
            if isinstance(value, list):
                return [item.get("produto", item) if isinstance(item, dict) else {} for item in value]
    return []


def product_field(product: Dict[str, Any], *names: str) -> str:
    for name in names:
        cur: Any = product
        ok = True
        for part in name.split("."):
            if isinstance(cur, dict) and part in cur:
                cur = cur[part]
            else:
                ok = False
                break
        if ok and cur not in (None, ""):
            return clean_cell(cur)
    return ""


def fetch_product_by_id(args: argparse.Namespace, token: str, product_id: str) -> Tuple[Dict[str, Any], str]:
    if args.api_version == "v2":
        payload = api_get_v2(args, token, "produto.obter.php", {"id": product_id})
        status = int(payload.get("_http_status") or 200)
        if status >= 400 or status == 0:
            return {}, f"API V2 produto.obter.php retornou HTTP {status}"
        retorno = payload.get("retorno") if isinstance(payload, dict) else {}
        if isinstance(retorno, dict) and str(retorno.get("status", "")).upper() == "ERRO":
            return {}, f"API V2 produto.obter.php retornou erro para id {product_id}"
        produto = unwrap_product_v2(payload)
        if not produto:
            return {}, f"API V2 sem produto para id {product_id}"
        return produto, ""

    payload = api_get(args, token, f"/produtos/{quote(str(product_id), safe='')}")
    status = int(payload.get("_http_status") or 200)
    if status >= 400 or status == 0:
        return {}, f"API /produtos/{product_id} retornou HTTP {status}"
    return unwrap_product(payload), ""


def build_product_index(args: argparse.Namespace, token: str) -> Dict[str, Dict[str, Any]]:
    if args.api_version == "v2":
        raise SafeError("Indice por SKU nao suportado no modo V2 ao vivo sem ID do produto.")

    index: Dict[str, Dict[str, Any]] = {}
    seen_ids: set = set()
    offset = 0
    for page in range(1, args.max_pages + 1):
        payload = api_get(args, token, "/produtos", {"limit": args.page_limit, "offset": offset})
        status = int(payload.get("_http_status") or 200)
        if status >= 400 or status == 0:
            raise SafeError(f"API /produtos retornou HTTP {status} ao montar indice por SKU.")
        items = extract_items(payload)
        if not items:
            break
        new_count = 0
        for raw_item in items:
            item = unwrap_product(raw_item)
            item_id = product_field(item, "id", "idProduto", "id_produto")
            sku = product_field(item, "sku", "codigo", "codigo_sku")
            if item_id and item_id in seen_ids:
                continue
            if item_id:
                seen_ids.add(item_id)
                new_count += 1
            if sku:
                index[sku.upper()] = item
        if new_count == 0:
            break
        offset += args.page_limit
        time.sleep(args.api_sleep)
    return index


def collect_images_from_api_value(value: Any, key_hint: str = "") -> List[str]:
    urls: List[str] = []

    def add(url: str) -> None:
        clean = clean_cell(url)
        if clean.startswith("http") and clean not in urls:
            urls.append(clean)

    if isinstance(value, dict):
        for key, child in value.items():
            child_hint = f"{key_hint}.{key}" if key_hint else str(key)
            for url in collect_images_from_api_value(child, child_hint):
                if url not in urls:
                    urls.append(url)
    elif isinstance(value, list):
        for child in value:
            for url in collect_images_from_api_value(child, key_hint):
                if url not in urls:
                    urls.append(url)
    elif isinstance(value, str):
        hint = key_hint.lower()
        if any(word in hint for word in ("imagem", "image", "foto", "photo", "midia", "media", "url", "link")):
            if value.startswith("http"):
                add(value)
        for url in extract_http_image_urls(value):
            add(url)
    return urls


def extract_image_urls(product: Dict[str, Any], fallback_urls: Sequence[str]) -> List[str]:
    urls: List[str] = []
    for url in collect_images_from_api_value(product):
        if url not in urls:
            urls.append(url)
    for url in fallback_urls:
        if url not in urls:
            urls.append(url)
    return urls


def load_manifest(path: Path) -> Dict[str, Any]:
    if not path.is_file():
        return {"by_url_hash": {}, "by_file_hash": {}}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(data, dict):
            data.setdefault("by_url_hash", {})
            data.setdefault("by_file_hash", {})
            return data
    except json.JSONDecodeError:
        pass
    return {"by_url_hash": {}, "by_file_hash": {}}


def save_manifest(path: Path, manifest: Dict[str, Any]) -> None:
    path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")


def extension_from_response(url: str, content_type: str) -> str:
    content_type = (content_type or "").split(";", 1)[0].strip().lower()
    if content_type.startswith("image/"):
        ext = mimetypes.guess_extension(content_type)
        if ext:
            return ".jpg" if ext == ".jpe" else ext
    parsed = urlparse(url)
    suffix = Path(parsed.path).suffix.lower()
    if suffix in IMAGE_EXTENSIONS:
        return ".jpg" if suffix == ".jpeg" else suffix
    return ".jpg"


def find_existing_by_url_hash(dest_dir: Path, url_hash: str) -> Optional[Path]:
    for existing in dest_dir.glob(f"*-{url_hash[:12]}.*"):
        if existing.is_file():
            return existing
    return None


def download_image(args: argparse.Namespace, image: DownloadedImage, dest_dir: Path) -> DownloadedImage:
    dest_dir.mkdir(parents=True, exist_ok=True)
    manifest_path = dest_dir / ".olist-image-manifest.json"
    manifest = load_manifest(manifest_path)
    url_hash = sha256_text(normalize_url_for_hash(image.original_url_olist))
    image.url_hash = url_hash

    existing_meta = manifest.get("by_url_hash", {}).get(url_hash)
    if isinstance(existing_meta, dict) and existing_meta.get("local_file"):
        existing_file = Path(existing_meta["local_file"])
        if existing_file.is_file():
            image.local_file = str(existing_file)
            image.file_hash = str(existing_meta.get("file_hash") or "")
            image.downloaded = False
            image.status = "ok"
            return image

    existing_file = find_existing_by_url_hash(dest_dir, url_hash)
    if existing_file:
        image.local_file = str(existing_file)
        image.file_hash = sha256_file(existing_file)
        image.downloaded = False
        image.status = "ok"
        manifest["by_url_hash"][url_hash] = {
            "local_file": image.local_file,
            "file_hash": image.file_hash,
            "original_url_olist": image.original_url_olist,
        }
        manifest["by_file_hash"][image.file_hash] = {"local_file": image.local_file}
        save_manifest(manifest_path, manifest)
        return image

    if args.dry_run:
        image.status = "dry_run"
        image.error_message = "download skipped by --dry-run"
        return image

    request = Request(
        image.original_url_olist,
        headers={
            "User-Agent": USER_AGENT,
            "Accept": "image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8",
        },
        method="GET",
    )
    temp_path = dest_dir / f".download-{url_hash[:12]}.tmp"
    try:
        with urlopen(request, timeout=args.download_timeout) as response:
            content_type = response.headers.get("Content-Type", "")
            if content_type and not content_type.lower().startswith("image/"):
                raise SafeError(f"URL nao retornou imagem: Content-Type={content_type}")
            max_bytes = args.max_image_mb * 1024 * 1024
            total = 0
            digest = hashlib.sha256()
            with temp_path.open("wb") as out:
                while True:
                    chunk = response.read(1024 * 256)
                    if not chunk:
                        break
                    total += len(chunk)
                    if total > max_bytes:
                        raise SafeError(f"Imagem maior que limite de {args.max_image_mb} MB")
                    digest.update(chunk)
                    out.write(chunk)
            if total == 0:
                raise SafeError("Download retornou arquivo vazio")

            file_hash = digest.hexdigest()
            by_file = manifest.get("by_file_hash", {}).get(file_hash)
            if isinstance(by_file, dict) and by_file.get("local_file") and Path(by_file["local_file"]).is_file():
                image.local_file = str(Path(by_file["local_file"]))
                image.file_hash = file_hash
                image.downloaded = False
                image.status = "ok"
                temp_path.unlink(missing_ok=True)
            else:
                ext = extension_from_response(image.original_url_olist, content_type)
                final_path = dest_dir / f"{image.image_position:03d}-{url_hash[:12]}{ext}"
                if final_path.exists():
                    image.local_file = str(final_path)
                    image.file_hash = sha256_file(final_path)
                    image.downloaded = False
                else:
                    temp_path.replace(final_path)
                    image.local_file = str(final_path)
                    image.file_hash = file_hash
                    image.downloaded = True
                image.status = "ok"

            manifest["by_url_hash"][url_hash] = {
                "local_file": image.local_file,
                "file_hash": image.file_hash,
                "original_url_olist": image.original_url_olist,
            }
            if image.file_hash:
                manifest["by_file_hash"][image.file_hash] = {"local_file": image.local_file}
            save_manifest(manifest_path, manifest)
            return image
    except (HTTPError, URLError, TimeoutError, SafeError) as exc:
        temp_path.unlink(missing_ok=True)
        image.status = "error"
        image.error_message = str(exc)
        return image


class Uploader:
    def upload(self, local_file: Path, sku_key: str) -> Tuple[bool, str, str]:
        raise NotImplementedError

    def close(self) -> None:
        pass


class NoopUploader(Uploader):
    def __init__(self, reason: str) -> None:
        self.reason = reason

    def upload(self, local_file: Path, sku_key: str) -> Tuple[bool, str, str]:
        return False, "", self.reason


class LocalUploader(Uploader):
    def __init__(self, public_root: Path, remote_root: str, site_base_url: str) -> None:
        self.public_root = public_root
        self.remote_root = remote_root.strip("/\\")
        self.site_base_url = site_base_url.rstrip("/")

    def upload(self, local_file: Path, sku_key: str) -> Tuple[bool, str, str]:
        dest_dir = self.public_root / self.remote_root / sku_key
        dest_dir.mkdir(parents=True, exist_ok=True)
        dest = dest_dir / local_file.name
        if not dest.exists():
            shutil.copy2(local_file, dest)
        site_path = "/".join([self.remote_root.replace("\\", "/"), quote(sku_key), quote(local_file.name)])
        return True, f"{self.site_base_url}/{site_path}", ""


class FtpUploader(Uploader):
    def __init__(self, remote_root: str, site_base_url: str) -> None:
        server = os.getenv("FTP_SERVER")
        username = os.getenv("FTP_USERNAME")
        password = os.getenv("FTP_PASSWORD")
        if not server or not username or not password:
            raise SafeError("FTP_SERVER, FTP_USERNAME ou FTP_PASSWORD ausente.")
        port = int(os.getenv("FTP_PORT") or "21")
        self.base_dir = (os.getenv("FTP_REMOTE_DIR") or "/").strip() or "/"
        self.remote_root = remote_root.strip("/")
        self.site_base_url = site_base_url.rstrip("/")
        self.ftp = ftplib.FTP()
        self.ftp.connect(server, port, timeout=45)
        self.ftp.login(username, password)

    def ensure_dir(self, path: str) -> None:
        current = ""
        parts = [part for part in path.replace("\\", "/").split("/") if part]
        if path.startswith("/"):
            self.ftp.cwd("/")
        for part in parts:
            current = posixpath.join(current, part)
            try:
                self.ftp.mkd(part)
            except ftplib.error_perm:
                pass
            self.ftp.cwd(part)

    def upload(self, local_file: Path, sku_key: str) -> Tuple[bool, str, str]:
        remote_dir = posixpath.join(self.base_dir, self.remote_root, sku_key).replace("\\", "/")
        try:
            self.ensure_dir(remote_dir)
            remote_name = local_file.name
            exists = False
            try:
                exists = self.ftp.size(remote_name) is not None
            except ftplib.error_perm:
                exists = False
            if not exists:
                with local_file.open("rb") as fh:
                    self.ftp.storbinary(f"STOR {remote_name}", fh)
            site_path = "/".join([self.remote_root, quote(sku_key), quote(local_file.name)])
            return True, f"{self.site_base_url}/{site_path}", ""
        except Exception as exc:  # noqa: BLE001 - sanitized below
            return False, "", f"FTP upload falhou: {exc.__class__.__name__}"

    def close(self) -> None:
        try:
            self.ftp.quit()
        except Exception:
            pass


def choose_uploader(args: argparse.Namespace) -> Uploader:
    mode = args.upload_mode
    if mode == "auto":
        if os.getenv("FTP_SERVER") and os.getenv("FTP_USERNAME") and os.getenv("FTP_PASSWORD"):
            mode = "ftp"
        elif args.public_root and Path(args.public_root).exists():
            mode = "local"
        else:
            mode = "none"

    if mode == "none":
        return NoopUploader("upload skipped: FTP/local public root not configured")
    if mode == "local":
        if not args.public_root:
            raise SafeError("--public-root e obrigatorio em --upload-mode local.")
        return LocalUploader(Path(args.public_root), args.remote_upload_root, args.site_base_url)
    if mode == "ftp":
        return FtpUploader(args.remote_upload_root, args.site_base_url)
    raise SafeError(f"Modo de upload invalido: {args.upload_mode}")


def sql_quote(value: Any) -> str:
    if value is None:
        return "NULL"
    text = str(value)
    return "'" + text.replace("\\", "\\\\").replace("'", "''") + "'"


def product_match_sql(row: FinalImageRow, alias: str = "p") -> str:
    parts: List[str] = []
    if row.sku:
        parts.append(f"UPPER({alias}.sku) = UPPER({sql_quote(row.sku)})")
    if row.olist_product_id:
        product_id = sql_quote(row.olist_product_id)
        parts.append(
            f"({alias}.olist_product_id = {product_id} OR {alias}.olist_id = {product_id} OR {alias}.idProduto = {product_id})"
        )
    return " OR ".join(parts) if parts else "0"


def product_id_expr(row: FinalImageRow) -> str:
    return f"(SELECT p.id FROM olist_products p WHERE {product_match_sql(row, 'p')} ORDER BY CASE WHEN {('UPPER(p.sku) = UPPER(' + sql_quote(row.sku) + ')') if row.sku else '0'} THEN 0 ELSE 1 END, p.id LIMIT 1)"


def generate_sql(rows: Sequence[FinalImageRow]) -> str:
    statements: List[str] = [
        "-- ShopVivaliz Olist image sync DML",
        "-- Run after SafeMigrationRepairAgent or equivalent schema migration.",
        "START TRANSACTION;",
    ]

    product_keys: Dict[str, FinalImageRow] = {}
    for row in rows:
        if row.status not in {"linked", "uploaded", "ok"} or not row.site_url:
            continue
        key = row.sku.upper() if row.sku else f"id:{row.olist_product_id}"
        product_keys.setdefault(key, row)
        pid_expr = product_id_expr(row)
        linked_expr = f"IF({pid_expr} IS NULL, 0, 1)"
        values = {
            "product_local_id": pid_expr,
            "product_id": pid_expr,
            "olist_product_id": sql_quote(row.olist_product_id),
            "olist_id": sql_quote(row.olist_product_id),
            "sku": sql_quote(row.sku),
            "image_url": sql_quote(row.site_url),
            "site_url": sql_quote(row.site_url),
            "local_url": sql_quote(row.site_url),
            "original_url": sql_quote(row.original_url_olist),
            "original_url_olist": sql_quote(row.original_url_olist),
            "local_file": sql_quote(row.local_file),
            "position": str(row.image_position),
            "is_primary": "1" if row.is_primary else "0",
            "source": "'olist_api'",
            "status": "'active'",
            "url_hash": sql_quote(row.url_hash),
            "file_hash": sql_quote(row.file_hash),
            "dedupe_key": sql_quote(row.dedupe_key),
            "uploaded": "1" if row.uploaded else "0",
            "linked": linked_expr,
            "error_message": sql_quote(row.error_message),
        }
        columns = ", ".join(f"`{column}`" for column in values.keys())
        select_values = ", ".join(values.values())
        statements.append(
            f"INSERT INTO olist_product_images ({columns}, created_at, updated_at) "
            f"SELECT {select_values}, NOW(), NOW() "
            f"WHERE NOT EXISTS (SELECT 1 FROM olist_product_images WHERE dedupe_key = {sql_quote(row.dedupe_key)});"
        )
        statements.append(
            "UPDATE olist_product_images SET "
            f"product_local_id = COALESCE(product_local_id, {pid_expr}), "
            f"product_id = COALESCE(product_id, {pid_expr}), "
            f"olist_product_id = {sql_quote(row.olist_product_id)}, "
            f"olist_id = {sql_quote(row.olist_product_id)}, "
            f"sku = {sql_quote(row.sku)}, "
            f"image_url = {sql_quote(row.site_url)}, "
            f"site_url = {sql_quote(row.site_url)}, "
            f"local_url = {sql_quote(row.site_url)}, "
            f"original_url = {sql_quote(row.original_url_olist)}, "
            f"original_url_olist = {sql_quote(row.original_url_olist)}, "
            f"local_file = {sql_quote(row.local_file)}, "
            f"`position` = {row.image_position}, "
            f"is_primary = {1 if row.is_primary else 0}, "
            "source = 'olist_api', status = 'active', "
            f"url_hash = {sql_quote(row.url_hash)}, "
            f"file_hash = {sql_quote(row.file_hash)}, "
            f"uploaded = {1 if row.uploaded else 0}, "
            f"linked = {linked_expr}, "
            f"error_message = {sql_quote(row.error_message)}, "
            "updated_at = NOW() "
            f"WHERE dedupe_key = {sql_quote(row.dedupe_key)};"
        )

    for row in product_keys.values():
        match = product_match_sql(row, "p")
        statements.append(
            "UPDATE olist_products p SET "
            "primary_image_url = ("
            "SELECT i.image_url FROM olist_product_images i "
            "WHERE i.product_local_id = p.id AND i.status = 'active' AND i.image_url IS NOT NULL AND i.image_url <> '' "
            "ORDER BY i.is_primary DESC, i.`position` ASC, i.id ASC LIMIT 1"
            "), "
            "images_count = ("
            "SELECT COUNT(*) FROM olist_product_images i "
            "WHERE i.product_local_id = p.id AND i.status = 'active' AND i.image_url IS NOT NULL AND i.image_url <> ''"
            "), "
            "image_sync_status = IF(("
            "SELECT COUNT(*) FROM olist_product_images i "
            "WHERE i.product_local_id = p.id AND i.status = 'active' AND i.image_url IS NOT NULL AND i.image_url <> ''"
            ") > 0, 'linked', 'missing'), "
            "updated_at = NOW() "
            f"WHERE {match};"
        )

    statements.append("COMMIT;")
    return "\n".join(statements) + "\n"


def mysql_cli_available() -> bool:
    return shutil.which("mysql") is not None


def apply_sql_with_mysql(sql_text: str) -> Tuple[bool, str]:
    required = ["DB_HOST", "DB_NAME", "DB_USER"]
    missing = [name for name in required if not os.getenv(name)]
    if missing:
        return False, "DB env ausente: " + ", ".join(missing)
    if not mysql_cli_available():
        return False, "mysql CLI nao encontrado no ambiente."

    env = os.environ.copy()
    if os.getenv("DB_PASS"):
        env["MYSQL_PWD"] = os.getenv("DB_PASS", "")
    command = [
        "mysql",
        "-h",
        os.getenv("DB_HOST", "localhost"),
        "-P",
        os.getenv("DB_PORT", "3306"),
        "-u",
        os.getenv("DB_USER", ""),
        os.getenv("DB_NAME", ""),
    ]
    result = subprocess.run(command, input=sql_text, text=True, capture_output=True, env=env, timeout=120)
    if result.returncode == 0:
        return True, "applied"
    return False, (result.stderr or result.stdout or "mysql retornou erro").strip()[:1000]


def run_mysql_batch(sql_text: str) -> Tuple[bool, str]:
    required = ["DB_HOST", "DB_NAME", "DB_USER"]
    missing = [name for name in required if not os.getenv(name)]
    if missing:
        return False, ""
    if not mysql_cli_available():
        return False, ""

    env = os.environ.copy()
    if os.getenv("DB_PASS"):
        env["MYSQL_PWD"] = os.getenv("DB_PASS", "")
    command = [
        "mysql",
        "--batch",
        "--skip-column-names",
        "-h",
        os.getenv("DB_HOST", "localhost"),
        "-P",
        os.getenv("DB_PORT", "3306"),
        "-u",
        os.getenv("DB_USER", ""),
        os.getenv("DB_NAME", ""),
    ]
    result = subprocess.run(command, input=sql_text, text=True, capture_output=True, env=env, timeout=120)
    if result.returncode != 0:
        return False, ""
    return True, result.stdout


def lookup_product_local_ids(rows: Sequence[FinalImageRow]) -> Dict[str, str]:
    statements: List[str] = []
    for row in rows:
        if not row.site_url:
            continue
        statements.append(
            f"SELECT {sql_quote(row.dedupe_key)}, CAST(p.id AS CHAR) "
            f"FROM olist_products p WHERE {product_match_sql(row, 'p')} "
            f"ORDER BY CASE WHEN {('UPPER(p.sku) = UPPER(' + sql_quote(row.sku) + ')') if row.sku else '0'} THEN 0 ELSE 1 END, p.id LIMIT 1;"
        )
    if not statements:
        return {}
    ok, output = run_mysql_batch("\n".join(statements))
    if not ok:
        return {}
    found: Dict[str, str] = {}
    for line in output.splitlines():
        parts = line.split("\t")
        if len(parts) >= 2:
            found[parts[0]] = parts[1]
    return found


def write_download_csv(images: Sequence[DownloadedImage], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fieldnames = [
        "sku",
        "olist_product_id",
        "nome_produto",
        "image_position",
        "original_url_olist",
        "local_file",
        "url_hash",
        "file_hash",
        "downloaded",
        "status",
        "error_message",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as fh:
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        for image in images:
            writer.writerow({field: getattr(image, field) for field in fieldnames})


def write_final_csv(rows: Sequence[FinalImageRow], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fieldnames = [
        "sku",
        "olist_product_id",
        "product_local_id",
        "nome_produto",
        "image_position",
        "original_url_olist",
        "local_file",
        "site_url",
        "uploaded",
        "linked",
        "is_primary",
        "status",
        "error_message",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as fh:
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        for row in rows:
            writer.writerow({field: getattr(row, field) for field in fieldnames})


def sku_key_for(item: ProductInput, product: Dict[str, Any]) -> str:
    sku = item.sku or product_field(product, "sku", "codigo", "codigo_sku")
    product_id = item.olist_product_id or product_field(product, "id", "idProduto", "id_produto")
    return sanitize_path_part(sku, f"olist-{product_id or 'sem-id'}")


def process_products(args: argparse.Namespace, products: Sequence[ProductInput]) -> Tuple[List[DownloadedImage], List[Dict[str, Any]]]:
    token: Optional[str] = None
    sku_index: Optional[Dict[str, Dict[str, Any]]] = None
    downloaded: List[DownloadedImage] = []
    product_summaries: List[Dict[str, Any]] = []

    if not args.skip_api:
        token = resolve_access_token(args)

    for idx, item in enumerate(products, start=1):
        product: Dict[str, Any] = {}
        errors: List[str] = []
        if args.skip_api:
            product = {}
        else:
            assert token is not None
            if item.olist_product_id:
                product, error = fetch_product_by_id(args, token, item.olist_product_id)
                if error:
                    errors.append(error)
            if not product and item.sku:
                if sku_index is None:
                    log("Montando indice da API por SKU para fallback.")
                    sku_index = build_product_index(args, token)
                indexed = sku_index.get(item.sku.upper(), {})
                indexed_id = product_field(indexed, "id", "idProduto", "id_produto")
                if indexed_id:
                    product, error = fetch_product_by_id(args, token, indexed_id)
                    if error:
                        errors.append(error)
                else:
                    product = indexed

        sku = item.sku or product_field(product, "sku", "codigo", "codigo_sku")
        olist_id = item.olist_product_id or product_field(product, "id", "idProduto", "id_produto")
        name = item.nome_produto or product_field(product, "nome", "descricao", "name", "titulo")
        sku_key = sku_key_for(ProductInput(sku, olist_id, name, item.sheet_image_urls, item.source_row), product)
        urls = extract_image_urls(product, item.sheet_image_urls if args.allow_sheet_image_fallback else [])

        if not args.quiet:
            log(f"[{idx}/{len(products)}] SKU={sku or '-'} OlistID={olist_id or '-'} imagens={len(urls)}")
        if not args.quiet and not urls and errors:
            log("  Aviso: " + " | ".join(errors[:2]))

        product_summaries.append(
            {
                "sku": sku,
                "olist_product_id": olist_id,
                "nome_produto": name,
                "images_found": len(urls),
                "errors": errors,
            }
        )

        for position, url in enumerate(urls, start=1):
            image = DownloadedImage(
                sku=sku,
                sku_key=sku_key,
                olist_product_id=olist_id,
                nome_produto=name,
                image_position=position,
                original_url_olist=url,
            )
            dest_dir = args.storage_images / sku_key
            downloaded.append(download_image(args, image, dest_dir))
        time.sleep(args.api_sleep if not args.skip_api else 0)

    return downloaded, product_summaries


def build_final_rows(args: argparse.Namespace, images: Sequence[DownloadedImage]) -> List[FinalImageRow]:
    uploader = choose_uploader(args)
    rows: List[FinalImageRow] = []
    try:
        for image in images:
            site_url = ""
            uploaded = False
            error = image.error_message
            status = image.status
            if image.status == "ok" and image.local_file:
                uploaded, site_url, upload_error = uploader.upload(Path(image.local_file), image.sku_key)
                if upload_error:
                    error = "; ".join(filter(None, [error, upload_error]))
                    status = "upload_pending"
                elif uploaded:
                    status = "uploaded"
            dedupe_basis = "|".join([image.sku.upper() if image.sku else "", image.olist_product_id, image.url_hash])
            rows.append(
                FinalImageRow(
                    sku=image.sku,
                    olist_product_id=image.olist_product_id,
                    product_local_id="",
                    nome_produto=image.nome_produto,
                    image_position=image.image_position,
                    original_url_olist=image.original_url_olist,
                    local_file=image.local_file,
                    site_url=site_url,
                    uploaded=uploaded,
                    linked=False,
                    is_primary=image.image_position == 1,
                    status=status,
                    error_message=error,
                    url_hash=image.url_hash,
                    file_hash=image.file_hash,
                    dedupe_key=sha256_text(dedupe_basis),
                )
            )
    finally:
        uploader.close()
    return rows


def summarize(products: Sequence[ProductInput], images: Sequence[DownloadedImage], final_rows: Sequence[FinalImageRow]) -> Dict[str, int]:
    downloaded_ok = [image for image in images if image.status == "ok"]
    uploaded_ok = [row for row in final_rows if row.uploaded]
    linked_rows = [row for row in final_rows if row.linked]
    product_keys_with_image = {row.sku.upper() if row.sku else f"id:{row.olist_product_id}" for row in final_rows if row.site_url}
    product_keys = {item.sku.upper() if item.sku else f"id:{item.olist_product_id}" for item in products}
    return {
        "produtos_processados": len(products),
        "imagens_baixadas": len(downloaded_ok),
        "imagens_subidas": len(uploaded_ok),
        "imagens_vinculadas": len(linked_rows),
        "produtos_com_imagem": len(product_keys_with_image),
        "produtos_sem_imagem": max(0, len(product_keys - product_keys_with_image)),
    }


def write_json_report(path: Path, data: Dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


def parse_args(argv: Sequence[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Fluxo completo de imagens Olist/Tiny para ShopVivaliz.")
    parser.add_argument("--input", required=True, help="Planilha real exportada da Olist/Tiny (.xlsx ou .csv).")
    parser.add_argument("--api-version", choices=["v2", "v3"], default=os.getenv("OLIST_API_VERSION", "v3"))
    parser.add_argument("--api-base", default=os.getenv("OLIST_API_BASE_URL") or os.getenv("TINY_API_BASE_URL") or DEFAULT_API_BASE)
    parser.add_argument("--api-v2-base", default=os.getenv("TINY_API_V2_BASE_URL") or DEFAULT_API_V2_BASE)
    parser.add_argument("--token-url", default=os.getenv("OLIST_TOKEN_URL") or os.getenv("TINY_TOKEN_URL") or DEFAULT_TOKEN_URL)
    parser.add_argument("--site-base-url", default=os.getenv("SITE_BASE_URL") or os.getenv("BASE_URL") or DEFAULT_SITE_BASE_URL)
    parser.add_argument("--storage-images", type=Path, default=Path(os.getenv("OLIST_IMAGES_DIR", str(DEFAULT_STORAGE_IMAGES))))
    parser.add_argument("--reports-dir", type=Path, default=Path(os.getenv("OLIST_REPORTS_DIR", str(DEFAULT_REPORTS_DIR))))
    parser.add_argument("--remote-upload-root", default=os.getenv("OLIST_REMOTE_UPLOAD_ROOT") or DEFAULT_REMOTE_UPLOAD_ROOT)
    parser.add_argument("--public-root", default=os.getenv("PUBLIC_HTML_ROOT") or os.getenv("SHOPVIVALIZ_PUBLIC_ROOT") or "")
    parser.add_argument("--upload-mode", choices=["auto", "none", "local", "ftp"], default=os.getenv("OLIST_UPLOAD_MODE", "auto"))
    parser.add_argument("--db-mode", choices=["sql", "apply", "none"], default=os.getenv("OLIST_DB_MODE", "sql"))
    parser.add_argument("--skip-api", action="store_true", help="Nao consulta API; usado para teste seco com URLs da planilha.")
    parser.add_argument("--allow-sheet-image-fallback", action="store_true", help="Usa URLs da planilha apenas quando a API nao for usada/nao retornar imagens.")
    parser.add_argument("--dry-run", action="store_true", help="Nao baixa nem escreve em FTP/DB; ainda gera CSVs.")
    parser.add_argument("--quiet", action="store_true", help="Reduz logs por produto.")
    parser.add_argument("--page-limit", type=int, default=int(os.getenv("OLIST_PAGE_LIMIT", "100")))
    parser.add_argument("--max-pages", type=int, default=int(os.getenv("OLIST_MAX_PAGES", "50")))
    parser.add_argument("--limit", type=int, default=int(os.getenv("OLIST_SYNC_LIMIT", "0")), help="Limita produtos processados; 0 processa todos.")
    parser.add_argument("--api-timeout", type=int, default=int(os.getenv("OLIST_API_TIMEOUT", "45")))
    parser.add_argument("--download-timeout", type=int, default=int(os.getenv("OLIST_DOWNLOAD_TIMEOUT", "60")))
    parser.add_argument("--api-sleep", type=float, default=float(os.getenv("OLIST_SLEEP_SECONDS", "0.25")))
    parser.add_argument("--max-image-mb", type=int, default=int(os.getenv("OLIST_MAX_IMAGE_MB", "25")))
    return parser.parse_args(argv)


def main(argv: Sequence[str]) -> int:
    args = parse_args(argv)
    input_path = Path(args.input)
    if not input_path.is_file():
        raise SafeError(f"Planilha/CSV de entrada nao encontrado: {input_path}")

    args.reports_dir.mkdir(parents=True, exist_ok=True)
    args.storage_images.mkdir(parents=True, exist_ok=True)

    products = read_input_products(input_path)
    if args.limit > 0:
        products = products[: args.limit]
    converted_csv = args.reports_dir / "olist_produtos_entrada.csv"
    downloaded_csv = args.reports_dir / "olist_imagens_baixadas.csv"
    final_csv = args.reports_dir / "olist_imagens_site_mapeamento.csv"
    sql_path = args.reports_dir / "olist_imagens_site_mapeamento.sql"
    json_path = args.reports_dir / "olist_imagens_site_mapeamento.json"

    write_input_csv(products, converted_csv)
    log(f"Entrada convertida: {converted_csv} ({len(products)} produtos)")

    if args.dry_run:
        log("Modo dry-run ativo.")

    images, product_summaries = process_products(args, products)
    write_download_csv(images, downloaded_csv)
    log(f"CSV de downloads: {downloaded_csv}")

    final_rows = build_final_rows(args, images)
    sql_text = generate_sql(final_rows)
    if args.db_mode != "none":
        sql_path.write_text(sql_text, encoding="utf-8")
        log(f"SQL de vinculo gerado: {sql_path}")

    if args.db_mode == "apply" and not args.dry_run:
        ok, message = apply_sql_with_mysql(sql_text)
        if ok:
            product_ids = lookup_product_local_ids(final_rows)
            for row in final_rows:
                if row.uploaded:
                    row.product_local_id = product_ids.get(row.dedupe_key, "")
                    row.linked = True
                    row.status = "linked"
            log("SQL aplicado no banco via mysql CLI.")
        else:
            log(f"SQL nao aplicado: {message}")

    write_final_csv(final_rows, final_csv)
    summary = summarize(products, images, final_rows)
    report = {
        "ok": True,
        "input": str(input_path),
        "converted_csv": str(converted_csv),
        "downloaded_csv": str(downloaded_csv),
        "final_csv": str(final_csv),
        "sql": str(sql_path) if args.db_mode != "none" else "",
        "summary": summary,
        "products": product_summaries,
    }
    write_json_report(json_path, report)
    log(f"CSV final: {final_csv}")
    log(f"Relatorio JSON: {json_path}")
    log("Resumo final:")
    for key, value in summary.items():
        log(f"  {key}: {value}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv[1:]))
    except SafeError as exc:
        print(f"ERRO: {exc}", file=sys.stderr)
        raise SystemExit(2)
