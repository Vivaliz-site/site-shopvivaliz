<!-- MCP_CLIENT_CATALOG_PARITY_20261106 -->
> **ESTADO COMPROVADO EM 06/11/2026 — CHATGPT MCP NATIVO:** os clientes **ShopVivaliz Remote Control** e **ShopVivaliz Remote Control - Orquestrador** agora expoem `marina-a1-4c24g` nos `inputSchema.host.enum` das ferramentas `admin_command_run`, `task_submit` e `service_action`. Os enums desses seis contratos anunciam somente `always-free-arm-1787907847-26`, `Fred-Win`, `KOCEPSV` e `marina-a1-4c24g`, sem a VM excluida `shopvivaliz-free-a1`. **CHATGPT_CLIENT_ADMIN_COMMAND_PARITY=APTO_NESTA_SESSAO; CLIENT_TOOL_SCHEMA_3_OPERACOES=APTO_NESTA_SESSAO; PARIDADE_TOTAL_MCP_RDC=NAO_CERTIFICADA.**
> **TESTE REAL VIA MCP NATIVO (sem SSH/RDC):** `admin_command_run(host="marina-a1-4c24g", command="hostname -s; id -un", timeout=5)` retornou `exit_code=0` e `marina-a1-4c24g / ubuntu` nos dois apps. Evidencias: Central audit_id `9571c723-f6d6-4dce-a2f6-5bdeff286def`, Orquestrador audit_id `8436a1dc-a386-4804-b600-f7f2cc89ee04`. A divergencia de catalogo anteriormente documentada **nao se reproduz nesta sessao**.
> **EXECUCAO DOS AGENTES:** nao bloquear tarefas da Marina com base no snapshot historico. Descobrir o schema **real** da sessao, tentar a ferramenta MCP oficialmente disponivel no host/operacao autorizada, registrar sucesso ou recusa. Usar `MCP_NOT_EXPOSED` somente quando a ferramenta/host realmente faltar no cliente atual; nao inferir falta por documentos antigos. Continuam vigentes MFA, RBAC, grants, ownership/lease, branch protection, revisao humana obrigatoria e a proibicao de acessar/recriar a VM excluida. Ver [runbook](../ops/mcp-client-catalog-publication-2026-11-06.md) e [issue #3152](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3152).
<!-- /MCP_CLIENT_CATALOG_PARITY_20261106 -->

# ChatGPT Dev — bootstrap da conta corporativa

**Conta:** `dev@shopvivaliz.com.br`  
**Papel:** engenharia/desenvolvimento ShopVivaliz  
**Migração:** substituição curada do contexto operacional da conta `fredmourao`; não é cópia integral do histórico.

## Ordem obrigatória de leitura

1. `docs/knowledge/host-access.md`
2. `docs/knowledge/README.md`
3. `docs/knowledge/agent-rules.md`
4. `docs/knowledge/project.md`
5. `docs/knowledge/browser-sessions.md`
6. `docs/knowledge/chatgpt-account-migration-curated.md`
7. `docs/knowledge/dev-agent-briefing.md`
8. documentação específica do projeto afetado.

## Identidade e sessão

- Usar exclusivamente o perfil `/home/fredrdp/.config/shopvivaliz-dev-chromium`.
- Porta CDP reservada: `9559`.
- Nunca fazer logout, trocar conta ou copiar cookies/storage dos perfis `fredmourao` ou `atendimento`.
- A existência deste documento não comprova autenticação; validar a identidade ao vivo antes de operar.

## Contexto migrado

Preservar somente conhecimento durável, decisões vigentes, regras, arquitetura, incidentes reutilizáveis e tarefas abertas revalidadas. Chats casuais, duplicados, tentativas intermediárias e status antigos ficam fora.

Histórico nunca supera evidência viva, código atual ou documentação canônica.

## Segurança

Nunca migrar ou registrar senha, token, cookie, chave, OTP/TOTP, seed ou conteúdo de secret. Usar apenas fontes seguras já provisionadas e validar autenticação por chamada real.

## Critério antes de remover fredmourao do Business

A troca de assento só está pronta quando `dev@shopvivaliz.com.br`:
- estiver adicionada ao workspace Business correto;
- estiver autenticada em seu perfil isolado;
- conseguir acessar os repositórios e ferramentas necessários;
- tiver plugins/conectores necessários configurados e testados;
- passar o bootstrap em conversa nova;
- identificar corretamente hosts, repositórios, regras de deploy e continuidade;
- executar um teste inofensivo pelo Remote Control com evidência fresca.

Até esses itens passarem, não remover `fredmourao` do workspace.

## Account access parity

Dev and Atendimento must have the same effective ShopVivaliz tool access,
workspace role and application permissions, including separate authorized
RDC 2 and ShopVivaliz MCP connections. Follow [`chatgpt-access-parity.md`](chatgpt-access-parity.md)
for the verification and acceptance gates. Do not treat two provider links
bound to the same Dev identity as independent Dev/Atendimento access.
