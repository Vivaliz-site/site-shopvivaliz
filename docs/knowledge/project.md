# ShopVivaliz

## Visão geral
Sistema ERP e e-commerce com automação de catálogo, Olist, IA de imagens e agentes autônomos.

## Objetivo
Centralizar operações de e-commerce com automação, integração e inteligência artificial.

## Domínio
dev.shopvivaliz.com.br (HostGator, deploy via GitHub Actions → FTP)

## Módulos principais
- Admin (`/admin/`)
- Catálogo (`/catalogo`, `api/catalog/`)
- Olist / Tiny ERP integration
- Imagens IA (`scripts/generate-ai-images.py`)
- Anúncios (Shopee, TikTok)
- Squad Chat (`/api/agent/squad-chat.php`)
- EHA — Enterprise Health Automation (`/automation/eha/`)
- Checkout + Pedidos (`/checkout/`, `/admin/pedidos.php`)

## Stack
- PHP 8+ (HostGator shared hosting)
- JavaScript (vanilla, localStorage cart)
- GitHub Actions CI/CD
- Python scripts (sync, quality engine, image gen)
- Tiny API v3 (OAuth Bearer)
- Olist / Tiny ERP

## Repositório
https://github.com/fredmourao-ai/site-shopvivaliz.git
