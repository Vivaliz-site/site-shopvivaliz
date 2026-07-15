#!/usr/bin/env python3
import argparse
import csv
import json
import os
import time
from pathlib import Path
from urllib.parse import urlparse, unquote
from urllib.parse import urlencode
from urllib.request import urlopen


API_URL = "https://api.tiny.com.br/api2/produto.obter.php"


def load_dotenv(path):
    if not path.exists():
        return
    for line in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key and key not in os.environ:
            os.environ[key] = value


def resolve_token():
    for name in ("TINY_API_TOKEN", "TOKEN_API_OLIST", "OLIST_API_TOKEN"):
        value = os.environ.get(name, "").strip()
        if value:
            return value, name
    raise SystemExit("Token V2 ausente. Defina TINY_API_TOKEN, TOKEN_API_OLIST ou OLIST_API_TOKEN.")


def pick(row, names):
    normalized = {k.strip().lower(): v for k, v in row.items()}
    for name in names:
        value = normalized.get(name.lower(), "")
        if str(value).strip():
            return str(value).strip()
    return ""


def row_image_urls(row):
    urls = []
    for key, value in row.items():
        key_l = str(key).strip().lower()
        if key_l.startswith("url imagem") or key_l.startswith("url imagem externa"):
            value = str(value or "").strip()
            if value:
                urls.append(value)
    return urls


def as_list(value):
    if not value:
        return []
    if isinstance(value, list):
        return value
    return [value]


def extract_url(item):
    if isinstance(item, str):
        return item
    if not isinstance(item, dict):
        return ""
    current = item
    if len(current) == 1 and isinstance(next(iter(current.values())), dict):
        current = next(iter(current.values()))
    for key in ("url", "link", "anexo", "imagem", "src"):
        value = current.get(key)
        if value:
            return str(value)
    return json.dumps(item, ensure_ascii=False)


def normalize_url(url):
    return str(url or "").strip()


def image_name(url):
    value = str(url or "").strip()
    if not value:
        return ""
    try:
        parsed = urlparse(value)
        name = Path(unquote(parsed.path)).name
        return name or value
    except Exception:
        return value


def fetch_product(token, product_id, timeout=45):
    params = urlencode({"token": token, "id": product_id, "formato": "json"})
    with urlopen(f"{API_URL}?{params}", timeout=timeout) as response:
        return json.loads(response.read().decode("utf-8", errors="replace"))


def main():
    parser = argparse.ArgumentParser(description="Valida imagens vinculadas no Tiny API V2 a partir de CSV completo.")
    parser.add_argument("--csv", required=True)
    parser.add_argument("--out", default="")
    parser.add_argument("--limit", type=int, default=0)
    parser.add_argument("--sleep", type=float, default=0.25)
    args = parser.parse_args()

    load_dotenv(Path(".env"))
    token, token_source = resolve_token()

    csv_path = Path(args.csv)
    if not csv_path.exists():
        raise SystemExit(f"CSV nao encontrado: {csv_path}")

    out_path = Path(args.out) if args.out else Path("reports") / f"tiny-imagens-validacao-{time.strftime('%Y%m%d-%H%M%S')}.csv"
    out_path.parent.mkdir(parents=True, exist_ok=True)

    rows = []
    with csv_path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        for row in reader:
            rows.append(row)

    if args.limit > 0:
        rows = rows[: args.limit]

    fields = [
        "id",
        "sku",
        "descricao",
        "csv_imagens_count",
        "api_anexos_count",
        "api_imagens_externas_count",
        "api_total_imagens",
        "planilha_imagens_nomes",
        "api_anexos_nomes",
        "api_imagens_externas_nomes",
        "planilha_nao_encontradas_na_api_count",
        "api_nao_encontradas_na_planilha_count",
        "status",
        "planilha_nao_encontradas_na_api",
        "api_nao_encontradas_na_planilha",
        "api_anexos_urls",
        "api_imagens_externas_urls",
        "erro",
    ]

    stats = {"ok_com_imagem": 0, "ok_sem_imagem": 0, "divergente": 0, "erro": 0}
    with out_path.open("w", encoding="utf-8-sig", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        for index, row in enumerate(rows, start=1):
            product_id = pick(row, ["ID", "id"])
            sku = pick(row, ["Código (SKU)", "Codigo (SKU)", "SKU", "sku"])
            descricao = pick(row, ["Descrição", "Descricao", "nome"])
            csv_urls = row_image_urls(row)
            result = {
                "id": product_id,
                "sku": sku,
                "descricao": descricao,
                "csv_imagens_count": len(csv_urls),
                "api_anexos_count": 0,
                "api_imagens_externas_count": 0,
                "api_total_imagens": 0,
                "planilha_imagens_nomes": " | ".join([image_name(url) for url in csv_urls]),
                "api_anexos_nomes": "",
                "api_imagens_externas_nomes": "",
                "planilha_nao_encontradas_na_api_count": 0,
                "api_nao_encontradas_na_planilha_count": 0,
                "status": "",
                "planilha_nao_encontradas_na_api": "",
                "api_nao_encontradas_na_planilha": "",
                "api_anexos_urls": "",
                "api_imagens_externas_urls": "",
                "erro": "",
            }
            if not product_id:
                result["status"] = "erro"
                result["erro"] = "ID ausente na planilha"
                stats["erro"] += 1
                writer.writerow(result)
                continue
            try:
                payload = fetch_product(token, product_id)
                retorno = payload.get("retorno") or {}
                if str(retorno.get("status", "")).upper() == "ERRO":
                    errors = retorno.get("erros") or retorno.get("erro") or retorno
                    raise RuntimeError(json.dumps(errors, ensure_ascii=False))
                produto = retorno.get("produto") or {}
                anexos = as_list(produto.get("anexos"))
                externas = as_list(produto.get("imagens_externas"))
                anexos_urls = [extract_url(item) for item in anexos if extract_url(item)]
                externas_urls = [extract_url(item) for item in externas if extract_url(item)]
                result["api_anexos_count"] = len(anexos_urls)
                result["api_imagens_externas_count"] = len(externas_urls)
                result["api_total_imagens"] = len(anexos_urls) + len(externas_urls)
                result["api_anexos_urls"] = " | ".join(anexos_urls)
                result["api_imagens_externas_urls"] = " | ".join(externas_urls)
                result["api_anexos_nomes"] = " | ".join([image_name(url) for url in anexos_urls])
                result["api_imagens_externas_nomes"] = " | ".join([image_name(url) for url in externas_urls])
                api_urls = anexos_urls + externas_urls
                csv_set = {normalize_url(url) for url in csv_urls if normalize_url(url)}
                api_set = {normalize_url(url) for url in api_urls if normalize_url(url)}
                missing_in_api = sorted(csv_set - api_set)
                extra_in_api = sorted(api_set - csv_set)
                result["planilha_nao_encontradas_na_api_count"] = len(missing_in_api)
                result["api_nao_encontradas_na_planilha_count"] = len(extra_in_api)
                result["planilha_nao_encontradas_na_api"] = " | ".join(missing_in_api)
                result["api_nao_encontradas_na_planilha"] = " | ".join(extra_in_api)
                if result["api_total_imagens"] == 0:
                    result["status"] = "ok_sem_imagem"
                elif missing_in_api or extra_in_api:
                    result["status"] = "divergente"
                else:
                    result["status"] = "ok_com_imagem"
                stats[result["status"]] += 1
            except Exception as exc:
                result["status"] = "erro"
                result["erro"] = str(exc)[:500]
                stats["erro"] += 1
            writer.writerow(result)
            f.flush()
            print(f"[{index}/{len(rows)}] {sku or product_id}: {result['status']}", flush=True)
            if args.sleep > 0:
                time.sleep(args.sleep)

    summary = {
        "csv": str(csv_path),
        "out": str(out_path),
        "rows": len(rows),
        "token_source": token_source,
        "stats": stats,
    }
    print(json.dumps(summary, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
