#!/usr/bin/env python3
"""Audit conversion surfaces for the active autonomous CRO mission."""
from __future__ import annotations

import json
import re
from datetime import UTC, datetime
from pathlib import Path

ROOT = Path(".")
LOGS_DIR = ROOT / "logs"
JSON_REPORT = LOGS_DIR / "cro-surface-audit.json"
MD_REPORT = LOGS_DIR / "cro-surface-audit.md"

SURFACES = [
    {
        "id": "home",
        "label": "Home",
        "path": Path("home.php"),
        "signals": [
            {"id": "hero_cta", "label": "CTA principal acima da dobra", "pattern": r"hero-btn", "weight": 3},
            {"id": "category_shortcuts", "label": "Atalhos de categoria", "pattern": r"categories-grid", "weight": 2},
            {"id": "featured_products", "label": "Produtos em destaque", "pattern": r"Produtos em destaque", "weight": 2},
            {"id": "contact_cta", "label": "CTA de contato", "pattern": r"/contato", "weight": 1},
        ],
        "opportunities": [
            {
                "id": "lead_capture",
                "label": "Adicionar captura de lead comercial",
                "pattern": r"newsletter|lead|whatsapp",
                "hint": "Inserir um CTA de contato comercial ou captacao curta acima da dobra.",
            },
        ],
    },
    {
        "id": "catalog",
        "label": "Catalogo",
        "path": Path("catalogo.php"),
        "signals": [
            {"id": "search", "label": "Busca visivel", "pattern": r"catalog-search", "weight": 3},
            {"id": "filters", "label": "Filtros de categoria", "pattern": r"category-filters", "weight": 2},
            {"id": "buy_now", "label": "CTA comprar agora", "pattern": r"Comprar agora", "weight": 3},
            {"id": "price_visibility", "label": "Preco ou status de consulta", "pattern": r"product-price", "weight": 2},
        ],
        "opportunities": [
            {
                "id": "trust_strip",
                "label": "Adicionar faixa de confianca no catalogo",
                "pattern": r"Compra 100% segura|Envio para todo Brasil|troca",
                "hint": "Exibir uma faixa curta de confianca perto da grade de produtos.",
            },
        ],
    },
    {
        "id": "product",
        "label": "Produto",
        "path": Path("produto.php"),
        "signals": [
            {"id": "structured_data", "label": "JSON-LD de produto", "pattern": r"application/ld\+json", "weight": 3},
            {"id": "canonical", "label": "Canonical SEO", "pattern": r"<link rel=\"canonical\"", "weight": 2},
            {"id": "buy_cta", "label": "CTA principal de compra", "pattern": r"id=\"buy-now\"", "weight": 3},
            {"id": "related_products", "label": "Bloco de produtos relacionados", "pattern": r"related-products", "weight": 2},
            {"id": "breadcrumb", "label": "Breadcrumb", "pattern": r"breadcrumb", "weight": 1},
        ],
        "opportunities": [
            {
                "id": "trust_block",
                "label": "Adicionar bloco de confianca na pagina de produto",
                "pattern": r"Compra segura|Envio para todo Brasil|troca",
                "hint": "Inserir confianca e entrega proximos ao CTA principal.",
            },
            {
                "id": "support_cta",
                "label": "Adicionar CTA de suporte comercial",
                "pattern": r"WhatsApp|contato",
                "hint": "Criar um CTA de ajuda por WhatsApp ou atendimento comercial ao lado do CTA de compra.",
            },
        ],
    },
    {
        "id": "cart",
        "label": "Carrinho",
        "path": Path("carrinho.php"),
        "signals": [
            {"id": "checkout_cta", "label": "CTA para checkout", "pattern": r"Finalizar pedido", "weight": 3},
            {"id": "quantity_controls", "label": "Controle de quantidade", "pattern": r"qty-btn", "weight": 2},
            {"id": "trust_copy", "label": "Copia de confianca", "pattern": r"Compra segura|Envio para todo Brasil|troca", "weight": 2},
            {"id": "continue_shopping", "label": "CTA continuar comprando", "pattern": r"Continuar comprando", "weight": 1},
        ],
        "opportunities": [
            {
                "id": "abandonment_recovery",
                "label": "Adicionar recuperacao leve de abandono",
                "pattern": r"salvar|continuar depois|whatsapp",
                "hint": "Oferecer um caminho de retomada simples para carrinhos abandonados.",
            },
        ],
    },
    {
        "id": "checkout",
        "label": "Checkout",
        "path": Path("checkout.php"),
        "signals": [
            {"id": "progress", "label": "Indicador de progresso", "pattern": r"checkout-progress", "weight": 2},
            {"id": "cep_autofill", "label": "Autopreenchimento de CEP", "pattern": r"ViaCEP|postal-code", "weight": 2},
            {"id": "payment_options", "label": "Opcoes de pagamento", "pattern": r"payment_method", "weight": 2},
            {"id": "trust_badges", "label": "Selos de confianca", "pattern": r"trust-badges", "weight": 2},
            {"id": "summary", "label": "Resumo lateral do pedido", "pattern": r"checkout-summary-card", "weight": 2},
        ],
        "opportunities": [
            {
                "id": "guest_checkout_copy",
                "label": "Explicitar checkout sem cadastro",
                "pattern": r"sem cadastro|sem criar conta",
                "hint": "Adicionar uma linha curta removendo friccao de cadastro obrigatorio.",
            },
            {
                "id": "support_reassurance",
                "label": "Reforcar suporte comercial",
                "pattern": r"WhatsApp|atendimento",
                "hint": "Exibir suporte rapido perto do botao principal para reduzir inseguranca.",
            },
        ],
    },
]


def file_text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def present(pattern: str, text: str) -> bool:
    return re.search(pattern, text, flags=re.IGNORECASE) is not None


def audit_surface(surface: dict) -> dict:
    path = surface["path"]
    text = file_text(path)

    found_weight = 0
    total_weight = sum(signal["weight"] for signal in surface["signals"])
    signals = []
    opportunities = []

    for signal in surface["signals"]:
        ok = present(signal["pattern"], text)
        signals.append({"id": signal["id"], "label": signal["label"], "present": ok})
        if ok:
            found_weight += signal["weight"]

    for opportunity in surface.get("opportunities", []):
        missing = not present(opportunity["pattern"], text)
        if missing:
            opportunities.append(
                {
                    "id": opportunity["id"],
                    "label": opportunity["label"],
                    "hint": opportunity["hint"],
                }
            )

    base_score = round((found_weight / total_weight) * 100) if total_weight else 0
    score = max(0, base_score - (len(opportunities) * 8))
    return {
        "id": surface["id"],
        "label": surface["label"],
        "path": str(path),
        "score": score,
        "signals": signals,
        "opportunities": opportunities,
    }


def build_report() -> dict:
    surfaces = [audit_surface(surface) for surface in SURFACES]
    return {
        "generated_at": datetime.now(UTC).isoformat(),
        "surfaces": surfaces,
        "priority_order": [
            surface["id"]
            for surface in sorted(
                surfaces,
                key=lambda surface: (surface["score"], -len(surface["opportunities"])),
            )
        ],
    }


def write_report(report: dict) -> tuple[Path, Path]:
    LOGS_DIR.mkdir(parents=True, exist_ok=True)
    JSON_REPORT.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    lines = ["# CRO Surface Audit", ""]
    for surface in sorted(report["surfaces"], key=lambda item: item["score"]):
        lines.append(f"## {surface['label']}")
        lines.append(f"- Path: `{surface['path']}`")
        lines.append(f"- Score: `{surface['score']}`")
        for item in surface["opportunities"]:
            lines.append(f"- Opportunity: {item['label']} — {item['hint']}")
        if not surface["opportunities"]:
            lines.append("- Opportunity: nenhuma lacuna prioritaria detectada por heuristica estatica.")
        lines.append("")

    MD_REPORT.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return JSON_REPORT, MD_REPORT


def main() -> int:
    report = build_report()
    json_path, md_path = write_report(report)
    print("CRO surface audit")
    for surface in sorted(report["surfaces"], key=lambda item: item["score"]):
        print(f"{surface['label']}: score={surface['score']} opportunities={len(surface['opportunities'])}")
    print(f"JSON saved at: {json_path}")
    print(f"Markdown saved at: {md_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
