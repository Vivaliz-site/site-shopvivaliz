# Backlog de Limpeza do Repositório

Este backlog registra o que foi concluído e o que ainda depende de validação externa ou remoção futura de compatibilidade.

## Status

- `concluído`: implementação e documentação aplicadas.
- `concluído-com-wrapper`: destino canônico criado e caminho antigo encaminha.
- `concluído-com-stub`: documento movido e origem virou ponte.
- `bloqueado-externo`: depende de credencial, provedor ou decisão externa.
- `aguarda-ci`: implementação pronta, aguardando checks.
- `remover-depois`: compatibilidade temporária ainda necessária.

## Estado dos lotes

| ID | Área | Estado | Resultado | Evidência/condição |
|---|---|---|---|---|
| CLEAN-001 | Olist/Tiny secrets | concluído | `OLIST_*` canônico; `TINY_*` apenas API Tiny nativa | mapa de secrets + `config/secrets.py` |
| CLEAN-002 | Hardcoded secrets | bloqueado-externo | token Olist removido da árvore atual; auditor reforçado | rotação no provedor ainda obrigatória; histórico Git não limpo |
| CLEAN-003 | Workflows | concluído | quatro workflows pausados removidos da pasta ativa e arquivados | `.github/workflows-archive/paused/` |
| CLEAN-004 | Shopee scripts | concluído-com-wrapper | implementações em `scripts/marketplace/shopee/` | workflow/testes usam caminhos canônicos |
| CLEAN-005 | Shopee produção | bloqueado-externo | estrutura de execução/evidência pronta | validação do primeiro produto depende de credencial Shopee válida |
| CLEAN-006 | Centralização de configuração | concluído | aliases concentrados em `config/secrets.py` | compilação e auditoria CI |
| CLEAN-007 | Documentos da raiz | concluído-com-stub | documentos operacionais/relatórios migrados em lotes | manifesto + stubs |
| CLEAN-008 | Logs/artifacts | concluído parcial | arquivo malformado de relatório Shopee arquivado | scanner deve confirmar outros candidatos |
| CLEAN-009 | Código experimental | concluído parcial | ferramentas históricas movidas para `scripts/dev/` | wrappers mantidos |
| CLEAN-010 | Testes | concluído | testes existentes separados em `unit` e `integration` | workflows atualizados |
| CLEAN-011 | Wrappers legados | remover-depois | wrappers preservam compatibilidade | remover somente após busca sem usos e CI verde |
| CLEAN-012 | Scanner global | concluído | scanner e relatório estrutural adicionados ao CI | artifact `repository-structure-*` |
| CLEAN-013 | Manifesto estrutural | concluído | origem/destino registrados em JSON | `validate_structure_manifest.py` |
| CLEAN-014 | Scripts IA | concluído-com-wrapper | implementações em `scripts/ai/` | manifesto e wrappers |
| CLEAN-015 | Scripts manutenção | concluído-com-wrapper | implementações em `scripts/maintenance/` | manifesto e wrappers |
| CLEAN-016 | Scripts Olist | concluído-com-wrapper | implementações em `scripts/marketplace/olist/` | manifesto e wrappers multilíngues |
| CLEAN-017 | Ferramentas raiz | concluído-com-wrapper | relatórios e dados em `scripts/dev/legacy-*` | manifesto e wrappers |
| CLEAN-018 | CI final | aguarda-ci | compileall, testes, scanner, manifesto e auditor configurados | todos os checks do PR precisam concluir verdes |

## Incidente de credencial

Foi encontrado um token em texto puro em documentação Olist. A versão corrente foi sanitizada e o incidente está em `docs/audits/security/credential-exposure-2026-07-30.md`.

Pendências obrigatórias fora do repositório:

1. rotacionar a credencial no provedor;
2. cadastrar o novo valor somente em secret protegido;
3. avaliar limpeza coordenada do histórico Git após a rotação.

## Compatibilidade a remover futuramente

- wrappers dos scripts listados em `config/repository-structure-manifest.json`;
- stubs documentais da raiz;
- aliases legados de secrets;
- arquivo trigger Shopee quando o fluxo manual deixar de ser necessário.

A remoção só pode ocorrer com busca sem referências, atualização do manifesto e CI verde.

## Histórico

- 2026-07-30: governança, índice, mapa de secrets, scanner e CI criados.
- 2026-07-30: Shopee, IA, manutenção e Olist migrados para caminhos canônicos.
- 2026-07-30: testes reorganizados.
- 2026-07-30: workflows pausados arquivados.
- 2026-07-30: documentos e relatórios da raiz migrados com stubs.
- 2026-07-30: ferramentas históricas da raiz movidas para `scripts/dev/`.
- 2026-07-30: exposição de credencial Olist removida da árvore atual e registrada.
