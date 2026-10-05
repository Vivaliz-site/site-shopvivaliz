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
