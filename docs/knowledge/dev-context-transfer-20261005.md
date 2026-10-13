# Transferência de contexto para dev@shopvivaliz.com.br — 2026-10-05

Este arquivo é o pacote curado de transferência operacional da conta `fredmourao` para `dev@shopvivaliz.com.br`.

Ele não é uma cópia do histórico de chats. O objetivo é preservar apenas contexto útil para desenvolvimento, operação, debugging, validação e continuidade dos projetos ShopVivaliz.

## Regras de uso

- Tratar este arquivo como contexto histórico curado, não como prova de estado atual.
- Antes de agir, revalidar runtime, provider, produção e código atual.
- Nunca pedir ou registrar secrets em Git.
- Auditoria significa investigar, corrigir, prevenir, testar e validar; não apenas listar erros.
- Não aceitar falso-verde.
- Continuar até `CONCLUIDO` com evidência fresca ou `BLOCKED_EXTERNAL` real.
- Remote Control MCP é a rota operacional primária.
- Navegadores ShopVivaliz devem rodar na VM/backend, não nos hosts Windows.
- Nunca editar diretamente release ativa ou `current/` em produção.

## Repositórios canônicos

- `Vivaliz-site/site-shopvivaliz` — site principal e base operacional.
- `Vivaliz-site/amazon-returns-safet` — Amazon Returns / SAFE-T.
- `Vivaliz-site/mercadolivre-returns-recovery` — devoluções Mercado Livre.
- `Vivaliz-site/-shopvivaliz-pipeline` — pipeline/automação.
- `fredmourao-ai/mei-mg-email` — automação MEI-MG.
- `fredmourao-ai/solange-rolla-consultorio` — canônico Solange Rolla.
- `fredmourao-ai/solange-rolla` — legado/supersedido; não usar como fonte canônica.

## Infraestrutura

- `shopvivaliz-free-a1` — produção web/deploy.
- `always-free-arm-1787907847-26` — backend/controller/browser/MEI/M365/relay.
- `shopvivaliz-ai` — legado DEV/e-mail/testes; nunca produção web.
- Deploy canônico: `/home/ubuntu/shopvivaliz-deploy` com releases imutáveis.
- GitHub runner é bootstrap/recovery; não é a rota operacional normal quando MCP está disponível.

## Sessões ChatGPT

A fonte final é `docs/knowledge/browser-sessions.md`.

Princípios:
- cada conta usa perfil Chromium dedicado;
- não fazer logout para trocar de conta;
- não copiar cookies/storage entre perfis;
- reparar a sessão correta em vez de usar outra conta como atalho.

## Amazon Returns / SAFE-T

Preservar como regra:
- reembolso proativo FBA até 50 dias;
- após prazo, recorrer via SAFE-T;
- encerramento real somente após crédito reconciliado (`CREDIT_CONFIRMED`);
- D+75;
- auditar 100% dos casos elegíveis;
- redação externa em primeira pessoa;
- arquitetura multi-tenant com isolamento de casos/eventos/evidências/outbox/cursores/políticas.

Último incidente relevante a revalidar:
- UI drift real no fluxo SAFE-T;
- razão histórica: `OUTBOX_UI_DRIFT_REASON_SAFE_T_ORDER_INPUT_MISSING`;
- mecanismo de rearm já havia sido validado;
- o campo de número do pedido na UI real precisava de nova detecção/validação.

Não assumir contagens ou filas históricas como atuais.

## Mercado Livre Returns

Preservar:
- ingestão, reconciliação e projeção idempotente;
- PostgreSQL 16;
- projector supervisionado;
- smoke/replay idempotente;
- falhas de provider devem ser distinguidas de defeitos locais.

Bloqueio histórico a revalidar:
- Claims/Billing retornava `PA_UNAUTHORIZED_RESULT_FROM_POLICIES`;
- Identity/Application/Orders estavam funcionais.

## MEI-MG Email

Preservar:
- stop-the-line por bounce;
- não reiniciar worker enquanto pausa operacional estiver ativa;
- reconciliador/monitor fazem parte do critério de retomada;
- corrigir causa e validar antes de reativar envio.

Estado histórico conhecido deve ser revalidado antes de qualquer ação.

## Solange Rolla

Preservar:
- repositório canônico: `fredmourao-ai/solange-rolla-consultorio`;
- `fredmourao-ai/solange-rolla` é legado;
- validação deve incluir rotina funcional/E2E real, não apenas testes estruturais.

## Site ShopVivaliz / continuidade / automações

Preservar:
- releases imutáveis;
- continuidade checkpoint-driven não deve ser pausada ou desabilitada;
- não reintroduzir guard que bloqueie retomada automática;
- workflows devem ser acionados apenas quando relacionados à tarefa/PR;
- falha de ferramenta, navegador, timeout ou runner não é automaticamente bloqueio externo;
- o controlador deve preferir evidência fresca e execução direta pelo MCP.

Arquitetura histórica de continuidade:
`watchdog -> task_resume_dispatcher -> executor real -> agent-operations-worker`.

O path operacional corrigido foi:
`shared/agent-task-state`.

## Integrações e regras de negócio

Integrações relevantes:
- Tiny ERP;
- Olist;
- Shopee;
- Mercado Livre;
- Amazon SP-API.

Regra permanente:
- “Devolução no ERP” é obrigatória para reembolso.

Regra histórica de promoções Mercado Livre:
- MC > 15%: ajustar até 15%;
- MC < 9%: excluir.

Revalidar regras comerciais no código/configuração antes de executar em massa.

## UI/UX e e-commerce

Problemas historicamente importantes para evitar regressão:
- preço de pré-venda incorreto;
- `R$0,00`;
- `0% OFF`;
- CTA “Falar com vendas” indevido;
- ausência de seleção de categoria;
- botão AI indevido;
- bot Liz;
- placeholders em blog/avaliações;
- avaliações não aparecendo;
- cards errados em `/ferramentas`;
- editor ML massivo;
- selos de confiança no carrinho;
- PWA/SEO/headless.

Todos devem ser tratados como histórico e revalidados antes de correção.

## Preferências operacionais duráveis

- Usar @Superpowers em investigação, debugging, planejamento, TDD, implementação, testes, revisão, deploy e validação.
- Não parar em diagnóstico quando há correção segura disponível.
- Hosts Windows são apoio/acesso remoto; tarefas de navegador ShopVivaliz ficam na VM.
- Sessões headless devem ser identificáveis, ter TTL e ser encerradas automaticamente.
- GitHub não deve substituir MCP como rota normal de operação.
- Segredos nunca entram em docs, prompts ou logs.

## O que não foi transferido

Não incluir:
- compras pessoais;
- interesses pessoais sem impacto no trabalho;
- chats casuais;
- mensagens repetitivas “siga/continue/retome” sem informação nova;
- códigos OTP/TOTP;
- passwords;
- tokens;
- cookies;
- chaves;
- dumps extensos sem valor durável;
- estados antigos apresentados como atuais.

## Bootstrap obrigatório da conta dev

Em uma conversa nova da conta `dev@shopvivaliz.com.br`, usar:

```text
Use Vivaliz-site/site-shopvivaliz main como fonte primária da ShopVivaliz.

Leia nesta ordem:
1. docs/knowledge/dev-chatgpt-bootstrap.md
2. docs/knowledge/host-access.md
3. docs/knowledge/README.md
4. docs/knowledge/agent-rules.md
5. docs/knowledge/project.md
6. docs/knowledge/browser-sessions.md
7. docs/knowledge/chatgpt-account-migration-curated.md
8. docs/knowledge/dev-agent-briefing.md
9. docs/knowledge/dev-context-transfer-20261005.md

Considere o contexto migrado como histórico curado. Revalide qualquer status operacional antes de agir. Use Remote Control MCP como rota primária. Não exponha secrets. Quando houver auditoria ou diagnóstico, corrija tudo que estiver seguro e ao alcance, teste e valide E2E/produção quando aplicável. Não aceite falso-verde e não encerre por simples falha de ferramenta.
```

## Critério de transferência concluída

A transferência de contexto está concluída quando este pacote estiver no `main` e a conta `dev` puder usá-lo como bootstrap sem depender do histórico bruto da conta antiga.

A remoção/inclusão de membros do workspace Business é uma etapa separada e não faz parte desta transferência.
