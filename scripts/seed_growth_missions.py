#!/usr/bin/env python3
"""Seed autonomous growth missions into the canonical task queue."""
from __future__ import annotations

from task_queue_lib import load_queue, save_queue, upsert_task

MISSIONS = [
    {
        "title": "Ativar geração autônoma de tarefas orientadas a crescimento",
        "description": "Configurar o orquestrador para gerar e priorizar automaticamente tarefas de crescimento, SEO, marketplace e CRO usando a fila canônica do projeto.",
        "priority": "high",
        "tags": ["automation", "growth", "orchestrator"],
        "source": "growth-mission-seed",
        "phase": "phase-1-foundation",
        "queue_rank": 10,
    },
    {
        "title": "Executar otimizações autônomas para vendas",
        "description": "Priorizar melhorias de conversão em página de produto, catálogo, checkout e captação de leads, sem alterar preços, descontos, fretes ou meios de pagamento.",
        "priority": "high",
        "tags": ["cro", "conversion", "ux"],
        "source": "growth-mission-seed",
        "phase": "phase-2-revenue",
        "queue_rank": 11,
    },
    {
        "title": "Conectar catálogo com Shopee sem impacto em preço",
        "description": "Validar integração Shopee existente, sincronizar produtos e mídia sem modificar preço, usando credenciais oficiais e rotina segura de marketplace.",
        "priority": "high",
        "tags": ["marketplace", "shopee", "catalog"],
        "requires_env": [
            "SHOPEE_PARTNER_ID",
            "SHOPEE_PARTNER_KEY",
            "SHOPEE_SHOP_ID",
            "SHOPEE_REFRESH_TOKEN"
        ],
        "source": "growth-mission-seed",
        "phase": "phase-3-marketplaces",
        "queue_rank": 12,
    },
    {
        "title": "Conectar catálogo com Mercado Livre sem impacto em preço",
        "description": "Validar OAuth e catálogo do Mercado Livre, publicar ou sincronizar anúncios sem alterar valores de venda, mantendo o Guardião de Preço intacto.",
        "priority": "high",
        "tags": ["marketplace", "mercado-livre", "catalog"],
        "requires_env": [
            "ML_CLIENT_ID",
            "ML_CLIENT_SECRET",
            "ML_REDIRECT_URI"
        ],
        "source": "growth-mission-seed",
        "phase": "phase-3-marketplaces",
        "queue_rank": 13,
    },
    {
        "title": "Preparar stack de Google Ads para automação",
        "description": "Preparar base técnica para campanhas, conversões e landing pages, com validação de credenciais e rastreamento, sem alterar regras financeiras do checkout.",
        "priority": "medium",
        "tags": ["ads", "google-ads", "tracking"],
        "requires_human_approval": True,
        "approval_scope": "ads_budget_approval",
        "requires_env": [
            "GOOGLE_ADS_CUSTOMER_ID",
            "GOOGLE_ADS_DEVELOPER_TOKEN",
            "GOOGLE_ADS_REFRESH_TOKEN"
        ],
        "source": "growth-mission-seed",
        "phase": "phase-4-approval-gated",
        "queue_rank": 14,
    },
    {
        "title": "Ligar domínio público à camada web ativa",
        "description": "Conectar domínio final à superfície publicada, validando apontamentos e roteamento. Esta etapa exige acesso ao provedor DNS ou domínio já gerenciado pela plataforma.",
        "priority": "medium",
        "tags": ["domain", "dns", "deploy"],
        "requires_manual_access": True,
        "source": "growth-mission-seed",
        "phase": "phase-4-approval-gated",
        "queue_rank": 15,
    },
    {
        "title": "Ativar SEO automático para catálogo e marketplace",
        "description": "Executar geração automática de SEO para páginas do site e canais externos, incluindo títulos, descrições, dados estruturados e auditoria contínua.",
        "priority": "high",
        "tags": ["seo", "catalog", "automation"],
        "source": "growth-mission-seed",
        "phase": "phase-2-revenue",
        "queue_rank": 16,
    },
    {
        "title": "Gerar páginas de produto dinâmicas e indexáveis",
        "description": "Preparar rota e renderização dinâmica de páginas de produto com dados estruturados, SEO consistente, conteúdo indexável e compatibilidade com catálogo atual.",
        "priority": "high",
        "tags": ["product-pages", "seo", "dynamic-content"],
        "source": "growth-mission-seed",
        "phase": "phase-2-revenue",
        "queue_rank": 17,
    },
    {
        "title": "Otimizar página campeã do ROI para conversão",
        "description": "Aplicar melhorias de copy, SEO, estrutura e CTA na página do produto de maior ROI, sem alterar preços, descontos, fretes ou meios de pagamento.",
        "priority": "high",
        "tags": ["sales_flow", "conversion", "seo", "product-pages"],
        "source": "growth-mission-seed",
        "phase": "phase-2-revenue",
        "queue_rank": 18,
    },
]


def main() -> int:
    queue = load_queue()
    created = 0
    updated = 0

    for mission in MISSIONS:
        _, was_created = upsert_task(queue, mission)
        if was_created:
            created += 1
        else:
            updated += 1

    save_queue(queue)
    print(f"Growth mission seed concluído: {created} criadas, {updated} atualizadas.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
