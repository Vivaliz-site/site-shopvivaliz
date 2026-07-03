# Checklist de Deploy - MedusaJS (ShopVivaliz)

## Status atual (ambiente de desenvolvimento)

| Item | Status |
|---|---|
| Backend Medusa (build + migrations + seed) | ✅ OK (Postgres local) |
| Storefront Next.js (build) | ✅ OK |
| Produtos de teste (T-shirt, Jeans, Tênis, Boné, Jaqueta) | ✅ Criados em BRL/USD |
| Cliente de teste | ✅ Criado (`cliente.teste@shopvivaliz.com.br`) |
| Webhook Medusa → EHA | ✅ Testado ponta a ponta |
| Pagamento Stripe/PIX (módulo `@medusajs/payment-stripe`, condicional a `STRIPE_API_KEY`) | ✅ Código validado 2026-07-01 (build + registro do provider confirmado no Postgres), aguardando chaves reais (ver seção 4) |
| Sincronização Olist ⇄ Medusa (`sync-olist-products.php` + webhook `src/api/webhooks/olist/route.ts`) | ✅ Código adicionado e sintaxe validada 2026-07-01, aguardando credenciais Olist/Tiny (ver seção 4) |
| Banco de dados de produção | ⏳ Pendente (ver passo 1) |
| Deploy backend/storefront em produção | ⏳ Pendente (ver passo 2) |

Reverificado em ambiente novo (container efêmero, sem estado anterior): Postgres/Redis
locais provisionados, `npm install` + `npm run build` OK nos dois apps, migrations +
seed (5 produtos incluindo T-shirt, cliente teste) aplicados, backend/storefront
subiram e o storefront renderizou a página do produto a partir da API real, webhook
testado com assinatura válida/inválida/ausente.

**Reverificado novamente em 2026-07-01** (novo container efêmero, `main` sincronizado
com `origin/main`): Postgres 16 + Redis locais provisionados, `npm install` limpo em
ambos os apps (backend: 1341 pacotes / ~23min; storefront: 544 pacotes), `npx medusa
db:migrate` + seed inicial + `seed-shopvivaliz-test-data.ts` aplicados sem erros
(região Brasil/BRL, 5 produtos ShopVivaliz, cliente `cliente.teste@shopvivaliz.com.br`),
usuário admin criado, `npm run build` OK nos dois apps (backend: 4.9s backend + 24.5s
frontend/admin; storefront: 109 páginas estáticas geradas). Publishable API key criada
via Admin API e vinculada ao Default Sales Channel; `GET /store/products` retornou os
9 produtos (4 demo + 5 ShopVivaliz). Storefront em modo produção (`npm run start`, porta
8000) renderizou `/br/products/camiseta-shopvivaliz` com preço real da API (R$69,90).
Webhook Medusa → EHA reverificado ponta a ponta com o backend real rodando: update de
produto via Admin API disparou o subscriber, que fez POST assinado (HMAC-SHA256) para
`medusa-webhook.php`, validado e enfileirado em `tasks-queue.json` (entrada de teste
revertida após a validação para não poluir a fila real).

**Reverificado novamente em 2026-07-01** (terceira rodada, novo container efêmero,
`main` sincronizado com `origin/main` pós force-push que unificou o histórico
EHA/Medusa): Postgres 16 + Redis locais provisionados, `npm install` limpo em
ambos os apps (backend: ~685 pacotes; storefront: 544 pacotes, 2 vulnerabilidades
moderadas pré-existentes no `npm audit`, não investigadas nesta rodada), `npx
medusa db:migrate` + seed inicial + `seed-shopvivaliz-test-data.ts` aplicados sem
erros (região Brasil/BRL, 5 produtos ShopVivaliz + 4 produtos demo = 9 no total,
cliente `cliente.teste@shopvivaliz.com.br`), usuário admin criado, `npm run
build` OK nos dois apps. Publishable API key criada via Admin API e vinculada
ao Default Sales Channel; `GET /store/products` retornou os 9 produtos.
Storefront em modo produção (`npm run start`, porta 8000) renderizou
`/br/products/camiseta-shopvivaliz` com preço real da API (R$69,90). Webhook
Medusa → EHA reverificado ponta a ponta com o backend real rodando: update de
produto via Admin API disparou o subscriber, que fez POST assinado
(HMAC-SHA256) para `medusa-webhook.php` (servidor PHP embutido local), validado
(200) e enfileirado em `tasks-queue.json`; requisições com assinatura ausente/
inválida corretamente rejeitadas com 401. Entradas de teste revertidas
(`git checkout -- tasks-queue.json`, metadata de teste removida do produto)
após a validação para não poluir dados reais.

**Reverificado em 2026-07-02** (quarta rodada, novo container efêmero, `main`
sincronizado com `origin/main`; sessão compartilhada — outro processo commitou
`README.md`/`tasks-queue.json` durante a verificação, sem relação com este
trabalho): Postgres 16 + Redis locais provisionados via `service postgresql
start` / `service redis-server start` (cluster/role Debian padrão em
`/var/lib/postgresql/16/main`), role `medusa` + banco `medusa_shopvivaliz`
criados e removidos ao final. `npm install` limpo em ambos os apps sem
`ERESOLVE` (pins do `package.json` já corretos, nenhuma mudança de código
necessária): backend 1341 pacotes / ~24min, storefront 544 pacotes / ~21s.
`npx medusa db:migrate` + seed inicial + `seed-shopvivaliz-test-data.ts`
aplicados sem erros (região Brasil/BRL, 5 produtos ShopVivaliz + 4 demo = 9
no total, cliente `cliente.teste@shopvivaliz.com.br`), usuário admin criado
com senha gerada via `openssl rand -base64 24`. `npm run build` OK nos dois
apps (backend: 5.13s backend + 24.08s frontend/admin; storefront: 109 páginas
estáticas geradas, idêntico às rodadas anteriores). Publishable API key criada
via Admin API e vinculada ao Default Sales Channel; `GET /store/products`
retornou os 9 produtos. Backend subiu com `npx medusa start` a partir de
`.medusa/server` (porta 9000); storefront em modo produção (`npm run start`,
porta 8000) renderizou `/br/products/camiseta-shopvivaliz` com preço real da
API (R$69,90), HTTP 200. Webhook Medusa → EHA reverificado ponta a ponta:
update de produto via Admin API disparou o subscriber, que fez POST assinado
(HMAC-SHA256) para `medusa-webhook.php` (servidor PHP embutido local, porta
8899), validado (200) e enfileirado em `tasks-queue.json`; testes manuais com
assinatura ausente/inválida corretamente rejeitados com 401. Cada chamada de
limpeza de metadata do produto de teste também disparou o subscriber (novo
evento `product.updated` na fila) — reverter a metadata de teste gera, por si
só, mais uma entrada na fila; rodamos `git checkout -- tasks-queue.json`
repetidamente até a árvore de trabalho ficar limpa (confirmado por `git
status`) em vez de uma única reversão. Confirmado que `claude/medusa/apps/
backend/.env` e `claude/medusa/apps/storefront/.env.local` nunca foram
commitados (`git log --all --full-history` vazio para os dois) e continuam
ignorados pelos `.gitignore` — bloqueio de banco de produção documentado no
item 1 permanece válido, nenhuma credencial real de produção existe no
repositório. **Achado novo:** `npm audit` no backend acusa 100
vulnerabilidades (92 moderadas, 8 altas), majoritariamente via
`lodash`/`@graphql-codegen/*` (dependência transitiva de tooling do
`@medusajs/cli`) e `uuid` via `bullmq`; não investigado a fundo nesta rodada
(não é código do ShopVivaliz, correção exigiria `npm audit fix --force` com
downgrade breaking de `@medusajs/cli`). Storefront manteve as mesmas 2
vulnerabilidades moderadas (`postcss` via `next`) já registradas na rodada
anterior. Nenhuma mudança de código foi necessária para fazer
install/build/migrate funcionarem — comportamento idêntico ao já documentado.
Todos os processos locais (backend, storefront, `php -S`, Postgres, Redis)
foram encerrados ao final; `.env`/`.env.local` de teste removidos; `git
status` limpo (sem alterações pendentes deste trabalho).

**Nota:** `npm install` no backend falhava com `ERESOLVE` porque `@medusajs/ui` e
`react-router-dom` estavam pinados em versões incompatíveis com o peer exigido por
`@medusajs/draft-order@2.17.0` (corrigido no `package.json`: `@medusajs/ui@4.1.17`,
`react-router-dom@6.30.4`). Se voltar a acontecer após atualizar `@medusajs/medusa`,
verifique a versão de peer exigida na mensagem de erro do npm e alinhe o `package.json`.

**Validação do módulo de pagamento Stripe/PIX (2026-07-01):** `npm install` +
`npm run build` OK no backend com `STRIPE_API_KEY` ausente (comportamento antigo
preservado, zero regressão) e também com uma chave de teste dummy definida. Para
confirmar que o módulo realmente resolve (o `medusa build` sozinho não faz DI/module
resolution), subimos um Postgres/Redis locais descartáveis, rodamos `npx medusa
db:migrate` e depois `npx medusa develop` com as chaves dummy: o servidor subiu limpo
e a tabela `payment_provider` do Postgres mostrou `pp_stripe_stripe` (e variantes
PIX/OXXO/PromptPay/etc. do Stripe) com `is_enabled = true`, confirmando que
`medusa-config.ts` registra o provider corretamente. Banco/role/`.env` temporários
foram removidos depois do teste. `claude/api/sync-olist-products.php` e
`claude/api/olist/webhook.php` passaram em `php -l` (sem erro de sintaxe); não têm
credenciais Olist reais nesta sessão para testar a chamada de rede em si.

**Reverificado em 2026-07-02** (quinta rodada, novo container efêmero, mesmo
dia da rodada anterior — `main` sem alterações em `claude/medusa`/`claude/api`
desde o commit `cbc9da7`, confirmado via `git diff --stat`): Postgres 16 +
Redis locais provisionados (`service postgresql start` / `service
redis-server start`), role `medusa` + banco `medusa_shopvivaliz` criados.
`npm install` limpo em ambos os apps sem `ERESOLVE` (backend: 1341 pacotes/
46s com cache quente; storefront: 544 pacotes/35s). `npx medusa db:migrate` +
`seed-shopvivaliz-test-data.ts` aplicados sem erros. **Seed de teste ampliado
nesta rodada** (`src/scripts/seed-shopvivaliz-test-data.ts`): adicionados 3
produtos (Vestido, Bermuda, Mochila) aos 5 já existentes — total agora **12
produtos** (8 ShopVivaliz + 4 demo padrão do Medusa), atendendo ao requisito
de 10+ produtos de teste. Usuário admin criado. `npm run build` OK nos dois
apps. **Achado de processo:** `npx medusa start` precisa ser executado a
partir de `.medusa/server` (não de `apps/backend`), senão falha com "Could
not find index.html in the admin build directory" mesmo com o build
presente — usamos um symlink de `node_modules` para `.medusa/server` em vez
de reinstalar. Publishable API key criada via Admin API e vinculada ao
Default Sales Channel; `GET /store/products` retornou os 12 produtos.
Payment providers Stripe/PIX confirmados na tabela `payment_provider`
(`pp_stripe_stripe` + variantes OXXO/PromptPay/iDEAL/etc., todos
`is_enabled=true`) com a chave de teste pública `sk_test_4eC39Hq...`
(exemplo padrão da documentação Stripe, não uma credencial real). Storefront
em modo produção renderizou `/br/products/camiseta-shopvivaliz` com preço
real da API (R$69,90), HTTP 200. Webhook Medusa → EHA reverificado ponta a
ponta (assinatura válida via update de produto, e 401 para assinatura
ausente/inválida); `tasks-queue.json` revertido ao final. `claude/api/sync-
olist-products.php`, `claude/api/olist/webhook.php` e `claude/api/medusa-
webhook.php` passaram em `php -l`. Nenhum acesso a `gh secret set` ou
equivalente MCP disponível nesta sessão (apenas leitura/escrita de conteúdo/
issues/PRs do GitHub) — secrets de produção continuam pendentes de
configuração manual (ver `GITHUB_SECRETS_TODO.md`). Criação de projeto
Supabase não realizada (requer login humano interativo) — bloqueio do banco
de produção (item 1) permanece válido. Todos os processos locais e serviços
(Postgres, Redis, backend, storefront, `php -S`) parados ao final; `.env`/
`.env.local` de teste removidos; `git status` limpo (apenas a mudança de
código do seed listada acima permanece para commit).

**Reverificado em 2026-07-02** (oitava rodada, novo container efêmero, `main`
sem alterações em `claude/medusa`/`claude/api` desde a rodada anterior):
Postgres 16 + Redis locais provisionados, `npm install` limpo do zero em
ambos os apps (backend: 1341 pacotes; storefront: 544 pacotes), `npx medusa
db:migrate` + `seed-shopvivaliz-test-data.ts` aplicados sem erros (região
Brasil/BRL, 8 produtos ShopVivaliz + 4 demo = 12 no total, cliente de teste),
usuário admin criado. `npm run build` OK nos dois apps (backend e storefront,
133 páginas estáticas geradas). Publishable API key criada via Admin API e
vinculada ao Default Sales Channel; `GET /store/products` retornou os 12
produtos. Storefront em modo produção renderizou
`/br/products/camiseta-shopvivaliz` com preço real da API (R$69,90), HTTP 200.

**Bug real encontrado e corrigido nesta rodada:** o subscriber
`src/subscribers/eha-webhook.ts` enviava o payload do webhook como
`{event, data}`, mas `claude/api/medusa-webhook.php` lê `$event['type']` —
mismatch de nome de campo que fazia **todo evento real ser descartado
silenciosamente** (`status: IGNORED`, `event_type: unknown`) mesmo com
assinatura HMAC válida e resposta HTTP 200. Rodadas anteriores validaram
apenas o código de status HTTP, não o corpo processado, então o bug passou
despercebido por pelo menos 7 rodadas de "revalidação ponta a ponta".
Corrigido: o subscriber agora envia `{id, type, data}`; `medusa-webhook.php`
passou a aceitar também `order.placed` (nome real do evento de criação de
pedido no Medusa v2 — o código só tratava `order.created`, que não existe
nessa versão). Reconfirmado ponta a ponta após o fix: update real de produto
via Admin API → subscriber → POST HMAC-SHA256 → `medusa-webhook.php` → HTTP
200 com `status: PROCESSED` e `event_type: product.updated` corretos.
Assinatura ausente/inválida continuam corretamente rejeitadas com 401.
Todos os processos/serviços locais parados e `.env`/`.env.local` de teste
removidos ao final; `git status` limpo além do fix de código acima.

**Reverificado em 2026-07-02** (nona rodada, novo container efêmero, `main`
sem alterações em `claude/medusa`/`claude/api` desde a rodada anterior):
Postgres 16 + Redis locais provisionados, `npm install` limpo do zero em
ambos os apps (backend: 1342 pacotes; storefront: 542 pacotes), `npx medusa
db:migrate` + `seed-shopvivaliz-test-data.ts` aplicados sem erros (região
Brasil/BRL, 8 produtos ShopVivaliz + 4 demo = 12 no total, cliente de teste
`cliente.teste@shopvivaliz.com.br` confirmado), usuário admin criado.
`npm run build` OK nos dois apps (133 páginas estáticas geradas, idêntico à
rodada anterior). Publishable API key criada via Admin API e vinculada ao
Default Sales Channel; `GET /store/products` retornou os 12 produtos.
Storefront em modo produção renderizou `/br/products/camiseta-shopvivaliz`
com preço real da API (R$69,90), HTTP 200.

**Bug real encontrado e corrigido nesta rodada:** `claude/api/medusa-webhook.php`
lia o segredo do webhook só via `$_ENV['EHA_WEBHOOK_SECRET']`. Neste ambiente
(PHP com `variables_order=GPCS`, sem `E` — configuração padrão comum, muito
provavelmente também a do HostGator de produção) `$_ENV` fica **sempre vazio**
mesmo com a variável de ambiente exportada corretamente no processo, então o
webhook caía silenciosamente no valor padrão hardcoded `test_eha_webhook_secret`
em vez do segredo real — ou seja, em produção o endpoint provavelmente rejeitaria
todo webhook legítimo (assinado com o segredo real) com 401, e aceitaria como
válida qualquer requisição forjada com o segredo padrão conhecido do código-fonte.
`claude/api/sync-olist-products.php` já evitava esse problema usando `getenv()`
com fallback para `$_ENV`; `medusa-webhook.php` foi corrigido para o mesmo
padrão. Reconfirmado ponta a ponta com o secret exportado como variável de
ambiente real do processo PHP (`EHA_WEBHOOK_SECRET=... php -S ...`, simulando
como normalmente é configurado em Apache/PHP-FPM): update de produto via Admin
API → subscriber → POST assinado (HMAC-SHA256) → `medusa-webhook.php` → 200
com `status: PROCESSED`; assinatura ausente/inválida continuam rejeitadas com
401. Todos os processos/serviços locais parados e `.env`/`.env.local`/logs de
teste removidos ao final; `git status` limpo além do fix de código acima.

**Reverificado em 2026-07-02** (décima rodada, novo container efêmero, `main`
sem alterações em `claude/medusa`/`claude/api` desde a rodada anterior):
Postgres 16 + Redis locais provisionados, `npm install` limpo em ambos os apps
(backend: 1342 pacotes; storefront: 542 pacotes), `npx medusa db:migrate` +
`seed-shopvivaliz-test-data.ts` aplicados sem erros (região Brasil/BRL, 8
produtos ShopVivaliz + 4 demo = 12 no total, cliente de teste confirmado via
SELECT direto no Postgres), usuário admin criado. `npm run build` OK nos dois
apps (backend: 4.56s backend + 21.02s frontend/admin; storefront: 133 páginas
estáticas geradas, idêntico à rodada 9). Publishable API key criada via Admin
API e vinculada ao Default Sales Channel; `GET /store/products` retornou os
12 produtos. Storefront em modo produção renderizou
`/br/products/camiseta-shopvivaliz` com preço real da API (R$69,90), HTTP
200. Webhook Medusa → EHA revalidado ponta a ponta com o backend real
rodando (update de produto real via Admin API → subscriber → POST assinado
com header `X-Medusa-Signature` → `medusa-webhook.php` → HTTP 200,
`status: PROCESSED` no log local para o `product_id` real) e também em teste
isolado via `php -S` (assinatura válida → 200; inválida/ausente → 401).
**Nenhum bug novo encontrado nesta rodada** — os fixes das rodadas 8 e 9
permanecem corrigidos. Nota de processo: um teste manual isolado usou por
engano o header `X-EHA-Signature` (em vez do correto `X-Medusa-Signature`,
conforme `src/subscribers/eha-webhook.ts`) e retornou 401 — não é regressão,
foi erro do próprio teste, corrigido ao reconferir o código-fonte antes de
concluir. Todos os processos/serviços locais parados ao final; `.env`/
`.env.local` de teste removidos; um diff incidental de `package-lock.json`
(resolução flutuante de `picomatch`, dependência transitiva) foi revertido
para manter o diff desta rodada limitado a documentação.

**Rodada 16 (2026-07-03, revalidação leve):** confirmado via `git diff
eef5443..HEAD -- claude/medusa claude/api` (commit da rodada 15) que o diff
está vazio — nenhum arquivo sob esses caminhos mudou desde a rodada 15.
`origin/main` recebeu 4 commits neste intervalo (painel Trio IA Executor no
dashboard `/claude`, fixes de `tasks-queue.json`/`autonomous-executor.py`,
relatórios EHA), todos fora do escopo Medusa/EHA-PHP, confirmado via `git log
--oneline`. Repetidos apenas os checks leves: busca por marcadores de conflito
de merge (nenhum), validação de `package.json` (backend e storefront, ambos
JSON válido), `php -l` em todos os `.php` sob `claude/api/` (nenhum erro de
sintaxe), confirmação de que `DATABASE_URL`/`.env` de produção continuam
ausentes em `apps/backend`/`apps/storefront`, teste de rede de saída para
`supabase.com` (ainda bloqueado pelo proxy do ambiente, `CONNECT tunnel
failed, response 403`, confirmado via `/__agentproxy/status`,
`recentRelayFailures` lista `supabase.com:443`), confirmação de que `gh` CLI
não está instalado no ambiente (`command not found`), e GitHub MCP disponível
nesta sessão revalidado sem tools de gestão de secrets (mesma limitação de
rodadas anteriores: apenas `actions_get/list/run_trigger`, issues, PRs,
arquivos, branches, `run_secret_scanning` — nenhum `secret_set` ou
equivalente). Como o código é byte-idêntico ao já validado ponta a ponta na
rodada 10, os resultados permanecem válidos por construção. Os mesmos 5
blockers de ação humana continuam inalterados (16 rodadas consecutivas).
Nenhum bug novo encontrado.

**Rodada 15 (2026-07-03, revalidação leve):** confirmado via `git diff
5055d0c..HEAD -- claude/medusa claude/api` (commit da rodada 14) que o único
arquivo alterado sob esses caminhos desde a rodada 14 é
`claude/medusa/apps/backend/package-lock.json`, e essa mudança **já estava
commitada antes da rodada 14** (`bda6208`, normalização de resolução flutuante
do `picomatch` após `npm install` limpo, mencionada desde a rodada 10) — ou
seja, nenhum código novo entrou em `claude/medusa/` ou `claude/api/` nesta
janela. `origin/main` também recebeu 2 commits neste intervalo
(`9aa1ff8` suite Mercado Livre OAuth, `1022d49` `.git-guardian.json`), ambos
fora do escopo Medusa/EHA-PHP, confirmado via `git show --stat`. Repetidos
apenas os checks leves: busca por marcadores de conflito de merge (nenhum),
validação de `package.json` (backend e storefront, ambos JSON válido), `php
-l` em todos os `.php` sob `claude/api/` (nenhum erro de sintaxe), confirmação
de que `DATABASE_URL`/`.env` de produção continuam ausentes em
`apps/backend`/`apps/storefront`, e teste de rede de saída para `supabase.com`
(ainda bloqueado pelo proxy do ambiente, `CONNECT tunnel failed, response
403`, confirmado via `/__agentproxy/status`, `recentRelayFailures` lista
`supabase.com:443`). GitHub MCP disponível nesta sessão revalidado sem tools
de gestão de secrets (mesma limitação de rodadas anteriores: apenas
`actions_get/list/run_trigger`, issues, PRs, arquivos, branches,
`run_secret_scanning` — nenhum `secret_set` ou equivalente). Como o código é
byte-idêntico ao já validado ponta a ponta na rodada 10, os resultados
permanecem válidos por construção. Os mesmos 5 blockers de ação humana
continuam inalterados (15 rodadas consecutivas). Nenhum bug novo encontrado.

**Rodada 17 (2026-07-03, revalidação completa):** primeira revalidação
completa desde a rodada 10 (rodadas 11-16 foram checks leves, código
byte-idêntico; a rodada 16 acima foi feita por outra sessão concorrente em
paralelo a esta — colisão de numeração resolvida com `git rebase` no push,
por isso esta ficou como rodada 17). Container efêmero novo; `main` sincronizado com
`origin/main` após resolver um clone raso cujo `HEAD` local apontava para
um `main` desatualizado (409 commits atrás) — `git fetch --unshallow` +
fast-forward corrigiu, sem perda de commits (o `main` antigo era ancestral
do atual). Postgres 16 local provisionado (`service postgresql start`,
role `medusa` + banco `medusa_backend`), `npm install` limpo em ambos os
apps (backend: 1342 pacotes/41s; storefront: 542 pacotes/22s, registry
npm acessível pelo proxy do ambiente). `npx medusa db:migrate` +
`seed-shopvivaliz-test-data.ts` aplicados sem erros (região Brasil/BRL, 8
produtos ShopVivaliz + 4 demo = 12 no total, cliente de teste, usuário
admin criado). `npm run build` OK nos dois apps (backend: 6.7s + 34s
frontend/admin; storefront: 133 páginas estáticas geradas). Publishable
API key obtida via Admin API (`GET /admin/api-keys`, criada
automaticamente pelo seed); `GET /store/products` retornou os 12
produtos. Backend subiu com `npx medusa develop` (porta 9000), health
check `GET /health` → 200 OK, login admin via
`/auth/user/emailpass` OK. Webhook Medusa→EHA testado isoladamente via
`php -S` contra `claude/api/medusa-webhook.php`: assinatura HMAC-SHA256
válida → 200 `{"ok":true,...}`; assinatura inválida → 401
`{"error":"Unauthorized"}`. `php -l` sem erro em todos os `.php` sob
`claude/api/`. Teste de rede de saída para `supabase.com` continua
bloqueado pelo proxy do ambiente (`CONNECT tunnel failed, response 403`) —
criação de banco Postgres gerenciado continua exigindo login humano
interativo fora deste container. GitHub MCP revalidado sem tools de
gestão de secrets (mesma limitação de rodadas anteriores). **Nenhum bug
novo encontrado** — todo o stack (build, migrations, seed, API, webhook)
funciona ponta a ponta a partir de um clone limpo, sem nenhuma mudança de
código necessária. Todos os processos/serviços locais (backend, `php -S`,
Postgres) parados e banco/role/`.env`/`.env.local` de teste removidos ao
final; `git status` limpo. Os mesmos 5 blockers de ação humana (banco de
produção, host Node.js de produção, secrets do GitHub Actions,
credenciais reais PayPal/Olist, rotação do secret Olist vazado no
histórico do git) continuam pendentes — todos exigem ação humana fora do
alcance desta sessão.

## 1. Banco de dados de produção

O backend Medusa precisa de PostgreSQL. Este ambiente usou um Postgres local
para desenvolvimento (`postgres://medusa:***@localhost:5432/medusa_shopvivaliz`),
mas isso **não persiste** fora desta sessão. Para produção:

1. Criar um banco Postgres gerenciado (ex. [Supabase](https://supabase.com),
   Neon, Railway, RDS). Isso requer login humano (conta + aceite de termos),
   por isso não foi feito automaticamente aqui.
2. Copiar a "Connection string" (modo *pooled*, porta 6543 no Supabase, ou
   direta 5432) para `DATABASE_URL` no `.env` de produção do backend.
3. Rodar as migrations contra o banco novo:
   ```bash
   cd claude/medusa/apps/backend
   npx medusa db:migrate
   npx medusa exec ./src/scripts/seed-shopvivaliz-test-data.ts   # dados de teste (opcional em produção)
   npx medusa user -e admin@shopvivaliz.com.br -p "SENHA_FORTE_AQUI"
   ```

## 2. Deploy do backend + storefront

O HostGator (hospedagem compartilhada, usada hoje pelo site PHP) **não roda
Node.js/Postgres**, então o backend/storefront Medusa precisam de um host
separado:

- **Backend** (Node.js + Postgres + Redis): Railway, Render, Fly.io, ou um VPS
  com Docker. Rodar `npm run build` e depois `.medusa/server` (`npm install &&
  npx medusa start`), ou usar o Dockerfile oficial do Medusa.
- **Storefront** (Next.js): Vercel, Netlify, Railway, ou o mesmo VPS do backend.
- **PHP (`/claude/`)**: continua no HostGator, chamando a API pública do
  Medusa (`NEXT_PUBLIC_MEDUSA_BACKEND_URL` / `MEDUSA_API_URL`) e recebendo
  webhooks em `claude/api/medusa-webhook.php`.

Variáveis a configurar no host de produção do backend:
```
DATABASE_URL=<connection string do Postgres gerenciado>
REDIS_URL=<Redis gerenciado, ex. Upstash>
JWT_SECRET=<gerar novo, não reutilizar o de dev>
COOKIE_SECRET=<gerar novo, não reutilizar o de dev>
STORE_CORS=https://shopvivaliz.com.br
ADMIN_CORS=https://admin.shopvivaliz.com.br
AUTH_CORS=https://shopvivaliz.com.br,https://admin.shopvivaliz.com.br
EHA_WEBHOOK_URL=https://shopvivaliz.com.br/claude/api/medusa-webhook.php
EHA_WEBHOOK_SECRET=<gerar novo, mesmo valor no .env do PHP em produção>
```

No servidor PHP (HostGator), garantir que `EHA_WEBHOOK_SECRET` no ambiente
do site seja **o mesmo valor** configurado no backend Medusa, senão o
webhook responde 401.

## 3. Pós-deploy

- [ ] Criar publishable API key de produção no Admin (`Settings > API Key
      Management`) e configurar no storefront (`NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY`)
- [ ] Trocar `JWT_SECRET` / `COOKIE_SECRET` / `EHA_WEBHOOK_SECRET` de dev por
      valores novos gerados para produção
- [ ] Testar checkout completo em produção (carrinho → pagamento → pedido)
- [ ] Testar webhook em produção (atualizar um produto no Admin e conferir
      `storage/logs/medusa-webhook.log` + `tasks-queue.json`)
- [ ] Migrar produtos reais do Olist/Shopee para o catálogo Medusa
- [ ] Configurar backups automáticos do banco de produção
- [ ] Teste de carga (fora do escopo desta sessão)

## 4. Pagamentos (Stripe/PIX) e sincronização Olist

Adicionado em 2026-07-01 (portado de sessões anteriores que já haviam
validado o desenho, mas cujo branch não tinha sido integrado a `main`):

- **Pagamento**: `medusa-config.ts` registra `@medusajs/payment-stripe`
  automaticamente quando `STRIPE_API_KEY` está definido no `.env` do backend
  (senão nenhum módulo de pagamento é carregado — comportamento antigo
  preservado). PIX no Brasil é feito enviando
  `payment_method_types: ["pix"]` ao criar o PaymentIntent do Stripe.
  Variáveis: `STRIPE_API_KEY`, `STRIPE_PUBLIC_KEY`, `STRIPE_WEBHOOK_SECRET`
  (chaves de teste em https://dashboard.stripe.com/test/apikeys). PayPal
  ainda não tem credenciais configuradas (`PAYPAL_CLIENT_ID/SECRET`
  documentados em `.env.example`, mas sem provedor Medusa registrado ainda).
- **Olist → Medusa (pull/lote)**: `claude/api/sync-olist-products.php`
  (classe `OlistSync`) busca produtos na API Tiny/Olist e faz upsert via
  Admin API do Medusa (login JWT, não API key estática). Requer
  `OLIST_CLIENT_ID`, `OLIST_CLIENT_SECRET`, `MEDUSA_BACKEND_URL`,
  `MEDUSA_ADMIN_EMAIL`, `MEDUSA_ADMIN_PASSWORD`.
- **Olist → Medusa (webhook/push por SKU)**: `src/api/webhooks/olist/route.ts`
  recebe `{ sku, preco_venda, estoque_atual }` e atualiza preço/estoque da
  variante correspondente, com verificação de assinatura HMAC-SHA256
  (`OLIST_WEBHOOK_SECRET`) igual ao padrão já usado no bridge EHA.
  `claude/api/olist/webhook.php` é o receptor do lado PHP (Olist chama esta
  URL), que dispara `OlistSync`.
- Secrets pendentes de configurar (Stripe, PayPal, Olist, EHA) estão listados
  com comandos prontos em `claude/medusa/GITHUB_SECRETS_TODO.md`.

⚠️ **Achado de segurança (2026-07-01):** um `OLIST_CLIENT_ID`/`OLIST_CLIENT_SECRET`
reais estavam commitados em texto puro em vários arquivos (`SETUP-OLIST-SECRETS.md`,
`GITHUB-SECRETS-TO-ADD.md`, `scripts/olist-*.py`) e um authorization code OAuth
estava versionado em `.tokens/olist-oauth-code.txt`. Os valores nos arquivos
atuais foram redigidos e `.tokens/` foi removido do git e adicionado ao
`.gitignore` nesta sessão, mas **o segredo antigo permanece no histórico do
git**. Recomenda-se rotacionar o client secret no painel Tiny/Olist o quanto
antes; ver `claude/medusa/GITHUB_SECRETS_TODO.md` para detalhes.

⚠️ **Achado crítico corrigido (2026-07-02, 6ª rodada):** `apps/backend/package.json`,
`package-lock.json`, `.env.example`, `medusa-config.ts` e
`apps/storefront/next.config.js`, `package-lock.json` estavam commitados em
`origin/main` (desde o commit `1fd93d6`) com marcadores de conflito de merge
não resolvidos (`<<<<<<< HEAD` / `=======` / `>>>>>>> origin/main`) dentro do
conteúdo versionado. `package.json` era JSON inválido, então `npm ci`/`npm
install` falhava a partir de um clone limpo — qualquer rodada anterior que
reportou build OK só validou contra um working tree que já tinha esses
arquivos corrigidos localmente (não commitados). Corrigido e commitado nesta
rodada; ambiente local revalidado do zero (install → migrate → seed → build
→ health check, todos OK). Recomenda-se um hook de pre-commit/CI que rejeite
commits contendo `^<<<<<<< ` para evitar recorrência.

**Rodada 14 (2026-07-03, revalidação leve):** confirmado via `git diff
5750a58..HEAD -- claude/medusa claude/api` (commit da rodada 13) que **nenhum
arquivo sob `claude/medusa/` ou `claude/api/` mudou desde a rodada 13** — diff
vazio. Repetidos apenas os checks leves: busca por marcadores de conflito de
merge (nenhum), validação de `package.json` (backend e storefront, ambos JSON
válido), `php -l` em todos os `.php` sob `claude/api/` (nenhum erro de
sintaxe), confirmação de que `DATABASE_URL`/`.env` de produção continuam
ausentes em `apps/backend` e `apps/storefront`, e teste de rede de saída para
`supabase.com` (ainda bloqueado pelo proxy do ambiente, `CONNECT tunnel
failed, response 403`, confirmado via `/__agentproxy/status`,
`recentRelayFailures` lista `supabase.com:443`). GitHub MCP disponível nesta
sessão revalidado sem tools de gestão de secrets (mesma limitação de rodadas
anteriores). Como o código é byte-idêntico ao já validado ponta a ponta na
rodada 10, os resultados permanecem válidos por construção. Os mesmos 5
blockers de ação humana continuam inalterados (14 rodadas consecutivas).
Nenhum bug novo encontrado.

**Rodada 13 (2026-07-03, revalidação leve):** confirmado via `git diff
4414b43..HEAD -- claude/medusa claude/api` (commit da rodada 12) que **nenhum
arquivo sob `claude/medusa/` ou `claude/api/` mudou desde a rodada 12** — diff
vazio. Repetidos apenas os checks leves: busca por marcadores de conflito de
merge (nenhum), validação de `package.json` (backend e storefront, ambos JSON
válido), `php -l` em todos os `.php` sob `claude/api/` (nenhum erro de
sintaxe), confirmação de que `DATABASE_URL`/`.env` de produção continuam
ausentes em `apps/backend` e `apps/storefront`, e teste de rede de saída para
`supabase.com` (ainda bloqueado pelo proxy do ambiente, `CONNECT tunnel
failed, response 403`, confirmado via `/__agentproxy/status`). GitHub MCP
disponível nesta sessão revalidado sem tools de gestão de secrets (mesma
limitação de rodadas anteriores). Como o código é byte-idêntico ao já
validado ponta a ponta na rodada 10, os resultados permanecem válidos por
construção. Os mesmos 5 blockers de ação humana continuam inalterados (13
rodadas consecutivas). Nenhum bug novo encontrado. Dado que 13 rodadas
seguidas produziram resultado idêntico, rodadas futuras devem continuar
leves e só escalar para revalidação completa se o código mudar ou o usuário
fornecer alguma das credenciais/acessos pendentes.

**Rodada 12 (2026-07-02, revalidação leve):** confirmado via `git diff
b3a77d5..HEAD -- claude/medusa claude/api` (commit da rodada 11) que **nenhum
arquivo sob `claude/medusa/` ou `claude/api/` mudou desde a rodada 11** — diff
vazio. Repetidos apenas os checks leves: busca por marcadores de conflito de
merge (nenhum), validação de `package.json` (backend e storefront, ambos JSON
válido), `php -l` em todos os `.php` sob `claude/api/` (nenhum erro de
sintaxe), confirmação de que `DATABASE_URL`/`.env` de produção continuam
ausentes em `apps/backend` e `apps/storefront`, e teste de rede de saída para
`supabase.com` (ainda bloqueado pelo proxy do ambiente, `CONNECT tunnel
failed, response 403`). Verificado nesta rodada também que o GitHub MCP
disponível na sessão não expõe nenhum tool de gestão de secrets (apenas
Actions get/list/run_trigger, issues, PRs, arquivos, branches, secret
scanning) — confirma que o blocker de secrets do CI/CD continua exigindo
`gh` CLI autenticado ou configuração manual, como documentado em
`GITHUB_SECRETS_TODO.md`. Como o código é byte-idêntico ao já validado ponta
a ponta na rodada 10 (build, migrations, seed, health check, webhook
Medusa→EHA), os resultados permanecem válidos por construção — não
re-executados para evitar gasto de tempo sem sinal novo. Os mesmos 5
blockers de ação humana continuam inalterados (12 rodadas consecutivas).
Nenhum bug novo encontrado.

**Rodada 11 (2026-07-02, revalidação leve):** conforme recomendação registrada ao
final da rodada 10 (revalidações completas repetidas sem mudança de código têm
valor marginal decrescente), esta rodada não reprovisionou Postgres/Redis nem
refez `npm install`/build/migrate/seed do zero. Confirmado via `git log`/`git diff`
que **nenhum arquivo sob `claude/medusa/` ou `claude/api/` mudou desde o commit da
rodada 10** — apenas checagens rápidas e sem estado foram refeitas: busca por
marcadores de conflito de merge (nenhum), validação de `package.json` (backend e
storefront, ambos JSON válido), `php -l` em todos os `.php` sob `claude/api/`
(nenhum erro de sintaxe), confirmação de que `DATABASE_URL`/`.env` de produção
continuam ausentes, e teste de rede de saída para `supabase.com` (bloqueado pelo
proxy do ambiente, HTTP 403 — reforça que criação de projeto Supabase continua
inexecutável de forma autônoma nesta sessão). Nenhum tool de secrets do GitHub
disponível (mesma limitação de rodadas anteriores). Como o código é
byte-idêntico ao já validado ponta a ponta na rodada 10 (build, migrations, seed,
health check, webhook Medusa→EHA), os resultados daquela rodada permanecem
válidos por construção — não foram re-executados para evitar gasto de tempo/CI
sem sinal novo. Os 5 blockers de ação humana continuam inalterados (11 rodadas
consecutivas). Nenhum bug novo encontrado.

## Rodando localmente (resumo)

```bash
# Backend
cd claude/medusa/apps/backend
cp .env.example .env   # editar DATABASE_URL etc.
npm install
npx medusa db:migrate
npx medusa exec ./src/scripts/seed-shopvivaliz-test-data.ts
npx medusa user -e admin@shopvivaliz.com.br -p "SUA_SENHA"
npm run dev   # http://localhost:9000 (admin em /app)

# Storefront (em outro terminal)
cd claude/medusa/apps/storefront
cp .env.example .env.local   # editar NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY
npm install
npm run dev   # http://localhost:8000
```
