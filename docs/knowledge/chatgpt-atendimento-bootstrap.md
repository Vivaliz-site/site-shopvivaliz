# ChatGPT Atendimento — Bootstrap ShopVivaliz

> Fonte persistente para a conta `atendimento@shopvivaliz.com.br`.
> Não registrar senhas, tokens, chaves, OTPs, cookies ou outros secrets neste arquivo.
> O repositório e a evidência viva prevalecem sobre memória de chat.

## Bootstrap obrigatório

Em toda nova conversa ShopVivaliz, leia nesta ordem:

1. `docs/knowledge/chatgpt-atendimento-bootstrap.md`
2. `docs/knowledge/host-access.md`
3. `docs/knowledge/README.md`
4. `docs/knowledge/agent-rules.md`
5. documentação específica da tarefa/projeto.

Depois confirme o estado real por código, runtime, logs, APIs ou UI. Memória do ChatGPT é auxiliar; não substitui a fonte versionada.

## Conta operacional

- ChatGPT: `atendimento@shopvivaliz.com.br`
- Reutilizar primeiro o perfil autenticado da VM backend.
- Navegador de agentes: sempre na VM `always-free-arm-1787907847-26`.
- Não usar Fred-Win/KOCEPSV para browser de tarefas ShopVivaliz.
- Nunca pedir senha/OTP antes de verificar sessões e fontes seguras já provisionadas.

## Projetos principais

- `Vivaliz-site/site-shopvivaliz`: e-commerce principal, storefront/admin, catálogo, pedidos, checkout, frete, pagamentos, SEO, PWA, Olist/Tiny, Mercado Livre, Shopee, Amazon, Buscador, Squad Chat, CI/CD, observabilidade e governança.
- `Vivaliz-site/amazon-returns-safet`: Amazon Returns / SAFE-T, ingestão de devoluções, eventos financeiros, reconciliação de crédito, Seller Central bridge, SAFE-T submit/appeal e multi-tenant.
- `Vivaliz-site/mercadolivre-returns-recovery`: Claims/Returns/Billing, reconciliação e recuperação financeira Mercado Livre.
- `Vivaliz-site/-shopvivaliz-pipeline`: controladores, continuidade, roteamento, runners, automações e governança.
- `fredmourao-ai/mei-mg-email`: automação de e-mail, bounce monitoring, safety gates e workers.
- `fredmourao-ai/solange-rolla-consultorio`: repositório canônico do sistema Solange Rolla; `fredmourao-ai/solange-rolla` é legado.
- `Vivaliz-site/buscador`: pesquisa profunda, debate contraditório e consenso OpenAI + Claude + Gemini.

## Infraestrutura canônica

### shopvivaliz-free-a1
- produção web/deploy
- origin `137.131.149.55`
- privado `10.0.1.112`
- deploy `/home/ubuntu/shopvivaliz-deploy`
- releases imutáveis; nunca editar `current/` nem release ativa.

### always-free-arm-1787907847-26
- backend, Remote Control MCP, navegador, controlador, MEI, M365 e relay Windows
- privado `10.0.1.38`
- sem IP público operacional.

### shopvivaliz-ai
- legado DEV/e-mail/testes
- nunca produção web.

### Windows
- Fred-Win / `LAPTOP-NIG4IFUU`
- KOCEPSV / `DESKTOP-KOCEPSV`
- não usar como browser/fallback para tarefas ShopVivaliz.

## Acesso operacional

Prioridade:
1. ShopVivaliz Remote Control MCP;
2. SSH privado/Tailscale apenas quando necessário;
3. GitHub Actions/OCI Bastion para bootstrap/recovery;
4. RustDesk para GUI.

Antes de qualquer ação, confirmar hostname, identidade, diretório e contexto Git quando aplicável.

## Regras essenciais

- Diagnóstico/auditoria: investigar → corrigir → prevenir → testar → validar.
- Não declarar sucesso só por HTTP 200, serviço ativo, arquivo presente ou teste superficial.
- UI exige navegador real da VM quando aplicável.
- Nunca expor secrets.
- Não editar produção diretamente.
- Preservar continuidade até `CONCLUIDO` validado ou bloqueio externo comprovado.
- Codex é última opção.
- Squad Chat saudável somente com `ok=true`, `endpoint=squad-chat` e `providers`.

## SAFE-T — regras funcionais-chave

- reembolso proativo FBA até 50 dias quando aplicável;
- depois recorrer SAFE-T quando elegível;
- fechar somente após crédito reconciliado (`CREDIT_CONFIRMED`);
- auditar 100% dos casos elegíveis;
- comunicação externa em primeira pessoa.

## Daybreak / uso defensivo

Uso exclusivamente defensivo e autorizado em ativos próprios ou ambientes com autorização explícita.

Casos de uso:
- revisão de segurança de código;
- análise de vulnerabilidades;
- threat modeling;
- investigação de incidentes;
- hardening;
- criação/revisão/validação de patches;
- segurança de infraestrutura e CI/CD;
- revisão de APIs e integrações;
- testes defensivos em e-commerce, marketplaces, automações e agentes;
- validação de controles e prevenção de recorrência.

Nunca incluir em formulários/chats externos: senhas, tokens, chaves, seeds TOTP, cookies ou conteúdo de secrets.

## Plugins/conectores esperados

Quando disponíveis e autorizados:
- ShopVivaliz Remote Control;
- GitHub;
- Gmail;
- Google Calendar;
- Google Drive;
- pesquisa/web;
- demais plugins estritamente necessários aos projetos.

Preferir Remote Control MCP para operações de host/browser.

## Prompt de inicialização recomendado

```
Use o repositório Vivaliz-site/site-shopvivaliz (main) como fonte primária.
Leia primeiro:
1. docs/knowledge/chatgpt-atendimento-bootstrap.md
2. docs/knowledge/host-access.md
3. docs/knowledge/README.md
4. docs/knowledge/agent-rules.md

Considere esse material como contexto operacional persistente da ShopVivaliz. Não exponha secrets. Para qualquer tarefa, confirme estado real por evidência antes de concluir. Use o ShopVivaliz Remote Control como rota operacional primária para hosts/browser e mantenha o navegador na VM backend. Quando eu pedir algo relacionado a um projeto, leia também a documentação específica daquele projeto antes de agir.
```
