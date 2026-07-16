#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Audita e gera SEO automático para catálogo/site e marketplaces.

Escopo seguro:
- lê apenas o catálogo local;
- gera recomendações e relatórios locais;
- não publica campanhas, não faz deploy e não altera preços.
"""
from __future__ import annotations

import json
import statistics
import sys
from datetime import UTC, datetime
from pathlib import Path
from typing import Any
from urllib.parse import quote

from seo_generator import SEOGenerator

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8")

ROOT = Path(__file__).resolve().parents[1]
CATALOG_PATH = ROOT / "api" / "catalog" / "fallback-products.json"
REPORT_JSON = ROOT / "logs" / "seo-automation-audit.json"
REPORT_MD = ROOT / "logs" / "seo-automation-audit.md"
BASE_URL = "https://dev.shopvivaliz.com.br"

COLOR_HINTS = {
    "preto", "preta", "branco", "branca", "azul", "vermelho", "vermelha", "verde",
    "amarelo", "amarela", "cinza", "rosa", "marrom", "bege", "prata", "dourado",
}
MATERIAL_HINTS = {
    "gel", "metal", "aluminio", "alumínio", "aco", "aço", "inox", "plastico",
    "plástico", "borracha", "nylon", "silicone", "madeira", "ferro",
}


def load_catalog() -> list[dict[str, Any]]:
    data = json.loads(CATALOG_PATH.read_text(encoding="utf-8"))
    return [row for row in data if isinstance(row, dict)]


def normalize_text(value: Any) -> str:
    return str(value or "").strip()


def product_url(product: dict[str, Any]) -> str:
    slug = normalize_text(product.get("slug"))
    if slug:
        return f"/produto/{slug}"

    sku = normalize_text(product.get("sku")) or normalize_text(product.get("id")) or "sem-sku"
    return "/produto?sku=" + quote(sku, safe="")


def infer_attributes(product: dict[str, Any]) -> dict[str, str]:
    tags = [normalize_text(tag).lower() for tag in product.get("tags", []) if normalize_text(tag)]
    quality_score = int(product.get("quality_score") or 0)

    color = next((tag.title() for tag in tags if tag in COLOR_HINTS), "")
    material = next((tag.title() for tag in tags if tag in MATERIAL_HINTS), "")
    style = next((tag.title() for tag in tags if tag not in COLOR_HINTS and tag not in MATERIAL_HINTS), "")

    if quality_score >= 90:
        quality = "Premium"
    elif quality_score >= 75:
        quality = "Alta"
    elif quality_score >= 60:
        quality = "Boa"
    else:
        quality = "Essencial"

    return {
        "color": color,
        "material": material,
        "quality": quality,
        "style": style,
    }


def site_description(product: dict[str, Any]) -> str:
    description = normalize_text(product.get("description"))
    if description:
        return description

    name = normalize_text(product.get("name")) or "Produto Vivaliz"
    category = normalize_text(product.get("category"))
    tags = [normalize_text(tag) for tag in product.get("tags", []) if normalize_text(tag)]

    category_part = f" da categoria {category}" if category else ""
    tag_part = f" ({', '.join(tags[:3])})" if tags else ""
    return (
        f"Confira {name}{category_part}{tag_part}. "
        "Produto de qualidade com compra segura, suporte comercial e entrega para todo o Brasil."
    )


def build_site_entry(product: dict[str, Any]) -> dict[str, Any]:
    name = normalize_text(product.get("name")) or "Produto Vivaliz"
    sku = normalize_text(product.get("sku")) or normalize_text(product.get("id")) or "sem-sku"
    image = normalize_text(product.get("image_url"))
    canonical_path = product_url(product)
    description = site_description(product)

    issues: list[str] = []
    if not normalize_text(product.get("slug")):
        issues.append("missing_slug")
    if not normalize_text(product.get("description")):
        issues.append("missing_catalog_description")
    if not image:
        issues.append("missing_image")
    if not normalize_text(product.get("category")):
        issues.append("missing_category")

    return {
        "sku": sku,
        "title": f"{name} | Vivaliz",
        "meta_description": description,
        "canonical_url": BASE_URL + canonical_path,
        "structured_data_ready": bool(name and canonical_path and image),
        "issues": issues,
    }


def markdown_report(report: dict[str, Any]) -> str:
    summary = report["summary"]
    site = summary["site"]
    marketplace = summary["marketplace"]
    gaps = report["gaps"]
    samples = report["samples"]

    lines = [
        "# Task 047 - SEO automation audit",
        "",
        f"- generated_at: {report['generated_at']}",
        f"- total_products: {summary['total_products']}",
        f"- site_meta_ready: {site['meta_ready']}",
        f"- site_structured_data_ready: {site['structured_data_ready']}",
        f"- avg_shopee_score: {marketplace['avg_shopee_score']}",
        f"- avg_tiktok_score: {marketplace['avg_tiktok_score']}",
        "",
        "## Gaps",
        "",
        f"- missing_slug: {gaps['missing_slug']}",
        f"- missing_catalog_description: {gaps['missing_catalog_description']}",
        f"- missing_image: {gaps['missing_image']}",
        f"- missing_category: {gaps['missing_category']}",
        "",
        "## Samples",
        "",
    ]

    for sample in samples:
        lines.extend(
            [
                f"### {sample['sku']}",
                "",
                f"- product: {sample['name']}",
                f"- site_title: {sample['site']['title']}",
                f"- shopee_title: {sample['marketplace']['shopee']['title']}",
                f"- tiktok_title: {sample['marketplace']['tiktok']['title']}",
                f"- issues: {', '.join(sample['site']['issues']) if sample['site']['issues'] else 'none'}",
                "",
            ]
        )

    return "\n".join(lines)


def main() -> int:
    products = load_catalog()
    generator = SEOGenerator()

    site_entries: list[dict[str, Any]] = []
    marketplace_entries: list[dict[str, Any]] = []
    shopee_scores: list[float] = []
    tiktok_scores: list[float] = []

    for product in products:
        site_entry = build_site_entry(product)
        site_entries.append(site_entry)

        seo_payload = {
            "sku": normalize_text(product.get("sku")) or normalize_text(product.get("id")) or "sem-sku",
            "name": normalize_text(product.get("name")),
            "category": normalize_text(product.get("category")),
            "attributes": infer_attributes(product),
            "price": float(product.get("price") or 0),
        }

        shopee_entry = generator.generate_shopee_seo(seo_payload)
        tiktok_entry = generator.generate_tiktok_seo(seo_payload)
        shopee_scores.append(float(shopee_entry.get("seo_score") or 0))
        tiktok_scores.append(float(tiktok_entry.get("seo_score") or 0))

        marketplace_entries.append(
            {
                "sku": seo_payload["sku"],
                "name": seo_payload["name"],
                "shopee": shopee_entry,
                "tiktok": tiktok_entry,
            }
        )

    gaps = {
        "missing_slug": sum(1 for item in site_entries if "missing_slug" in item["issues"]),
        "missing_catalog_description": sum(1 for item in site_entries if "missing_catalog_description" in item["issues"]),
        "missing_image": sum(1 for item in site_entries if "missing_image" in item["issues"]),
        "missing_category": sum(1 for item in site_entries if "missing_category" in item["issues"]),
    }

    report = {
        "generated_at": datetime.now(UTC).isoformat().replace("+00:00", "Z"),
        "summary": {
            "total_products": len(products),
            "site": {
                "meta_ready": sum(1 for item in site_entries if not item["issues"] or item["issues"] == ["missing_catalog_description"]),
                "structured_data_ready": sum(1 for item in site_entries if item["structured_data_ready"]),
            },
            "marketplace": {
                "avg_shopee_score": round(statistics.fmean(shopee_scores), 2) if shopee_scores else 0.0,
                "avg_tiktok_score": round(statistics.fmean(tiktok_scores), 2) if tiktok_scores else 0.0,
            },
        },
        "gaps": gaps,
        "samples": [
            {
                "sku": item["sku"],
                "name": market["name"],
                "site": item,
                "marketplace": {
                    "shopee": market["shopee"],
                    "tiktok": market["tiktok"],
                },
            }
            for item, market in zip(site_entries[:5], marketplace_entries[:5])
        ],
        "site": site_entries,
        "marketplace": marketplace_entries,
    }

    REPORT_JSON.parent.mkdir(parents=True, exist_ok=True)
    REPORT_JSON.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    REPORT_MD.write_text(markdown_report(report), encoding="utf-8")

    print("SEO automation audit")
    print(f"Produtos avaliados: {report['summary']['total_products']}")
    print(f"Site meta ready: {report['summary']['site']['meta_ready']}")
    print(f"Structured data ready: {report['summary']['site']['structured_data_ready']}")
    print(f"Shopee avg score: {report['summary']['marketplace']['avg_shopee_score']}")
    print(f"TikTok avg score: {report['summary']['marketplace']['avg_tiktok_score']}")
    print(f"JSON saved at: {REPORT_JSON.relative_to(ROOT)}")
    print(f"Markdown saved at: {REPORT_MD.relative_to(ROOT)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
