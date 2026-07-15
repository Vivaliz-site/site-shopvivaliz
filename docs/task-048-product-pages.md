# Task 048 - Páginas de produto dinâmicas e indexáveis

## Objetivo

- garantir que as URLs de produto continuem dinâmicas e compatíveis com o catálogo local;
- reforçar indexação segura para páginas válidas;
- impedir indexação de slugs inválidos ou produtos não encontrados.

## Escopo executado

- `produto.php`
  - fallback seguro para `404` com `noindex,follow` quando o produto não existe;
  - JSON-LD adicional de `BreadcrumbList`;
  - `mainEntityOfPage` no schema `Product`;
  - `og:site_name` e `twitter:card`;
  - reutilização de helper para URL canônica dos relacionados.
- `scripts/product-page-indexability-audit.py`
  - valida catálogo, unicidade de slugs, rewrite da rota e presença dos principais sinais de indexação.

## Governança preservada

- nenhuma alteração de preço, frete, pagamento ou campanhas;
- nenhuma publicação externa;
- nenhuma ação financeira;
- nenhum deploy.

## Validações previstas

- `php -l produto.php`
- `python scripts/product-page-indexability-audit.py`
- `python scripts/autonomous-continuous-cycle.py --advance` após conclusão
