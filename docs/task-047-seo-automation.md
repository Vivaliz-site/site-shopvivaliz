# Task 047 - SEO automático para catálogo e marketplace

## Objetivo

- ativar SEO técnico automático no catálogo sem alterar regras comerciais;
- gerar auditoria contínua local para páginas do site e canais externos;
- manter a preparação para Shopee e TikTok apenas em modo de recomendação, sem publicação.

## Escopo executado

- `catalogo.php`
  - metadados dinâmicos por categoria e busca;
  - canonical estável para catálogo e categorias;
  - `noindex,follow` em páginas de busca interna;
  - Open Graph completo com `og:url` e `og:site_name`;
  - JSON-LD `CollectionPage` com `ItemList` dos produtos visíveis.
- `sitemap.php`
  - inclusão automática das páginas de categoria do catálogo.
- `scripts/seo-automation-audit.py`
  - leitura do catálogo local;
  - geração de SEO de site, Shopee e TikTok em modo local;
  - relatório contínuo em `logs/seo-automation-audit.json` e `logs/seo-automation-audit.md`.

## Governança preservada

- nenhum preço foi alterado;
- nenhuma campanha foi publicada;
- nenhum deploy foi executado;
- nenhuma credencial foi exposta;
- nenhuma publicação em marketplace foi acionada.

## Validações previstas

- `php -l catalogo.php`
- `php -l sitemap.php`
- `python scripts/seo-automation-audit.py`
- `python scripts/autonomous-continuous-cycle.py --advance` apos conclusão da tarefa

## Próximo passo sugerido

- seguir para a `task-048` focando em páginas de produto dinâmicas e indexáveis, aproveitando o SEO técnico já consolidado nesta etapa.
