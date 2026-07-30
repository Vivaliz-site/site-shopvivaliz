# Mapa de Ownership Funcional

Este documento define donos funcionais por area do repositorio. O objetivo e evitar arquivos sem responsavel, rotinas duplicadas e integracoes sem governanca.

## Regra

Todo novo modulo, script, workflow, endpoint ou documento operacional deve ter uma area dona. Quando houver duvida, registrar primeiro como `Governanca de repositorio` e abrir item no backlog de limpeza.

## Areas

| Area | Escopo | Caminhos principais | Regras de mudanca |
|---|---|---|---|
| Site PHP legado | Storefront atual, paginas publicas, carrinho, checkout, includes e templates | `app/`, `api/`, `includes/`, raiz PHP quando existir | Validar no navegador/curl; nao alterar preco ou estoque sem fonte oficial |
| Marketplace Shopee | Cliente API, SEO, catalogo Shopee, tokens e relatorios | `scripts/*shopee*`, `scripts/utils/shopee_client.py`, `.github/workflows/*shopee*` | Exigir backup, limit, confirmacao, read-back e artifact |
| Olist ERP/Marketplace | Integracao Olist, OAuth, catalogo, pedidos e imagens ligados a Olist | `scripts/*olist*`, docs de integracao, secrets `OLIST_*` | `OLIST_*` e canonico; aliases antigos apenas em `config/secrets.py` |
| Tiny nativo | API Tiny quando usada diretamente fora da camada Olist | `scripts/*tiny*`, secrets `TINY_*` | Nao reutilizar `TINY_*` para Olist; documentar endpoint e token separado |
| Mercado Livre | Integracao ML, seller, callbacks e catalogo | arquivos com `ml`, `mercado`, `meli` | Separar tokens ML de Olist/Tiny/Shopee |
| Amazon | SP-API, LWA, catalogo, imagens | arquivos com `amazon`, `sp_api` | Separar LWA, AWS e seller/account identifiers |
| TikTok Shop | Autenticacao, catalogo e sync TikTok | arquivos com `tiktok` | Usar secrets canonicos `TIKTOK_*` |
| Deploy e infraestrutura | GitHub Actions, FTP, SSH, VM, Apache, SSL, rollback | `.github/workflows/`, deploy scripts, docs de deploy | Todo deploy precisa log, rollback ou plano de reversao |
| Banco de dados | SQL, migrations, integridade, reparos | `database/`, `migrations/`, scripts SQL | Migrations idempotentes; backup antes de alteracao destrutiva |
| Emails e notificacoes | SMTP, relatorios, alertas, agentes email | arquivos com `email`, `smtp`, `mail` | Usar `SMTP_*` como canonico; nao expor credenciais |
| Imagens e midia | Geracao, validacao, upload e politicas de imagem | `scripts/ia/images/`, docs de imagem | Nao usar placeholder/fake; validar origem e vinculacao |
| Agentes IA | Trio IA, filas, memoria operacional, regras | `ai_collaboration.py`, `agents/`, `tasks-queue.json`, `docs/knowledge/` | Atualizar memoria dos agentes e registro de rotinas |
| Governanca de repositorio | Indices, limpeza, auditorias, CI de higiene | `docs/knowledge/repository-index.md`, `docs/audits/`, `scripts/audit_repository.py` | Bloquear novas sujeiras via CI |

## Processo para arquivos sem dono

1. Registrar no `docs/audits/repository-cleanup-backlog.md`.
2. Classificar como `manter`, `migrar`, `renomear`, `arquivar`, `remover depois de validacao` ou `bloqueado`.
3. Atribuir uma area dona antes de alterar logica.
4. Nao deletar arquivo sem confirmar uso em workflow, script, include, endpoint, deploy e documentacao.
