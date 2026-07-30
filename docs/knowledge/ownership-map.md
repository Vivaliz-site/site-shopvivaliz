# Mapa de Ownership Funcional

Todo arquivo operacional deve pertencer a uma área. Arquivo sem dono deve ser registrado no backlog antes de ser alterado.

| Área | Caminhos canônicos | Responsabilidade | Regra de mudança |
|---|---|---|---|
| Site PHP | raiz PHP, `app/`, `api/`, `includes/` | Storefront, checkout, catálogo e endpoints | Validar sintaxe, HTTP e regra de negócio |
| Marketplace Shopee | `scripts/marketplace/shopee/`, workflows Shopee | Catálogo, SEO, imagens, tokens e evidência | Backup, confirmação, invariantes e read-back |
| Olist ERP | `scripts/marketplace/olist/`, `docs/operations/olist/` | OAuth, catálogo, pedidos, imagens e sync | `OLIST_*` canônico; nunca documentar token |
| Tiny nativo | fluxos explicitamente Tiny | API Tiny separada | `TINY_*` somente com endpoint/credencial próprios |
| Agentes IA | `scripts/ai/`, docs de agentes | Execução, fila, colaboração e observabilidade | Proibir simulação apresentada como execução real |
| Governança | `scripts/audit_repository.py`, `scripts/maintenance/restructure_repository.py`, manifesto e knowledge | Estrutura, higiene, índice e políticas | Toda mudança estrutural atualiza manifesto e documentos |
| Manutenção/QA | `scripts/maintenance/`, testes e CI | Diagnóstico, segurança, rollback e validação | Não alterar produção sem plano e evidência |
| Ferramentas legadas | `scripts/dev/legacy-*` | Utilitários históricos e locais | Não tratar como produção; revisar antes de executar |
| Workflows ativos | `.github/workflows/` | Automação GitHub Actions | Registrar gatilho, permissões, secrets e validação |
| Workflows arquivados | `.github/workflows-archive/paused/` | Histórico pausado | Não reativar sem PR específico |
| Deploy/Infra | deploy workflows, Apache/FTP/VM docs | Publicação, disponibilidade e reversão | Teste pós-deploy e rollback obrigatório |
| Segurança/Secrets | `config/secrets.py`, mapa de secrets, auditor | Nomes canônicos e prevenção de vazamento | Nunca armazenar valores; rotacionar exposição |
| Testes | `tests/unit/`, `tests/integration/`, `tests/smoke/` | Evidência automatizada | Teste acompanha caminho canônico |
| Documentação operacional | `docs/operations/` | Runbooks vigentes e históricos operacionais | Nada novo na raiz |
| Auditorias | `docs/audits/` | Relatórios, incidentes e backlog | Relatórios históricos não provam estado atual |
| Arquivo | `archive/<ano>/` | Artefatos substituídos/malformados | Registrar origem e substituto |

## Resolução de dúvida

1. Consultar `config/repository-structure-manifest.json`.
2. Consultar `docs/knowledge/repository-index.md`.
3. Se ainda sem dono, classificar como Governança e abrir item no backlog.
4. Não deletar nem mover sem verificar workflows, imports, endpoints e documentação.
