# ShopVivaliz - causa raiz real de 2 meses sem vendas via Google Ads

Data: 2026-09-03

## Resumo

A causa raiz não era Google Ads. Era o worker de pagamento (`shopvivaliz-queue-worker.service`)
preso a um diretório de release já apagado desde 2026-08-29, processando uma fila
(`storage/queue.json`) diferente da que os webhooks do Mercado Pago realmente escrevem a
cada novo deploy. Nenhum pagamento aprovado era gravado no banco desde 2026-07-31, em
qualquer canal — não só Google Ads.

## Como foi diagnosticado

1. `scripts/google_ads_review_campaigns.py` sempre retornava `AUTH_NOT_READY` /
   `GOOGLE_ADS_CLIENT_INIT_FAILED`, mas `classify_auth_error()` engolia a exceção real.
   Rodando `GoogleAdsClient.load_from_dict()` direto na VM `shopvivaliz-free-a1`
   (`/home/ubuntu/shopvivaliz-deploy/shared/.env`, carregado linha a linha via Python)
   revelou a exceção verdadeira: `ModuleNotFoundError: No module named 'google'`. A lib
   `google-ads` nunca esteve instalada em nenhum Python da VM (Ubuntu 24.04, PEP 668,
   `pip3 list` vazio). Criado venv dedicado em `shared/venv-google-ads` com
   `google-ads==31.1.0` (versão pinada em `requirements.txt`). Confirmado com chamada real
   de API: `customer_id=5283091103`, nome "Shopvivaliz Ltda" — as credenciais sempre
   estiveram válidas.
2. Com a API funcionando, `google_ads_review_campaigns.py` mostrou 3 campanhas `ENABLED`
   (nenhuma pausada), R$232,11 gastos em 30 dias, 427 cliques reais, **0 conversões**.
3. Consulta real ao banco de produção (`orders`, MySQL) mostrou 40 pedidos reais em 60
   dias (loja vende de verdade), mas **todos os 14 pedidos criados nos últimos 30 dias
   ficaram travados em `aguardando_pagamento`** — nenhum aprovado, nenhum cancelado. O
   último pagamento aprovado em qualquer canal foi em 2026-07-31.
4. `journalctl -u shopvivaliz-queue-worker.service` mostrou `errno=28 No space left on
   device` ao escrever a fila em 2026-09-02 (disco chegou perto de 100%, agravante). Mais
   grave: `/proc/<MainPID>/cwd` do worker apontava para
   `releases/20260829-224927-4cb3ceac (deleted)` — uma release já removida pela política
   de retenção (mantém só as últimas 5), enquanto `current` já tinha avançado por 6+
   releases. Um job real (webhook do Mercado Pago, pedido `SV20260831182823370`, boleto
   R$63,93, criado em 2026-08-31) foi encontrado com `status=queued` na fila da release
   atual, nunca processado pelo worker zumbi.

## Causa raiz

`core/queue/queue.php::SV_QUEUE_FILE` apontava, por padrão, para
`storage/queue.json` **dentro da pasta da release** (`releases/<timestamp>-<sha>/storage/queue.json`).
Releases são imutáveis e substituídas a cada deploy (`scripts/deploy-production.sh`), e as
mais antigas são apagadas pela retenção. `shopvivaliz-queue-worker.service` é um processo
de longa duração (`systemd`, `Restart=always`) que só resolve esse caminho de novo quando
reinicia — e nada reinicia esse worker automaticamente:

- `scripts/deploy-production.sh` tem `restart_runtime_services()`, que inclui esse
  serviço, mas o script **nunca conseguiu rodar** nesta VM: `deploy-production.sh` checa
  `[ ! -d "$REPO_DIR/.git" ]`, e `/home/ubuntu/shopvivaliz-deploy/repo` é, na verdade, um
  **git worktree** (`.git` é um arquivo apontando para
  `/home/ubuntu/recovery-two-a1/site-shopvivaliz-clean/.git/worktrees/repo`, resquício da
  migração de VM de 2026-08-28/29) — a checagem falha sempre com
  `[FATAL] Clone Git nao existe`.
- O workflow "oficial" (`master-production-pipeline.yml`) também não ajuda: ele faz SSH
  para `137.131.156.17`, a VM antiga, **terminada em 2026-08-29**. Uma busca em
  `.github/workflows/` confirma zero arquivos referenciando a VM atual
  (`163.176.103.253` / `shopvivaliz-free-a1`).
- Algum mecanismo não documentado publica código na VM atual mesmo assim (releases novas
  apareceram minutos depois do merge da correção abaixo), mas sem chamar
  `restart_runtime_services()` — confirmado ao vivo: o worker reiniciado manualmente às
  16:03 UTC já estava órfão de novo às 16:11 UTC, após esse deploy silencioso.

Resultado: todo deploy desde pelo menos 2026-07-31 (provavelmente antes) órfã o worker de
pagamento em silêncio. Nenhum alerta, nenhum erro visível ao usuário — só pedidos parados
para sempre em `aguardando_pagamento`.

## Correção aplicada

- PR [#1387](https://github.com/Vivaliz-site/site-shopvivaliz/pull/1387) (merged em
  `main`, deployado e testado ao vivo): `sv_queue_file_path()` agora resolve
  `storage/queue.json` em `shared/` (mesmo diretório persistente já usado por `.env`)
  sempre que existe um `shared/` irmão do diretório de releases — assim toda release e
  todo processo de longa duração concordam no mesmo arquivo, independente de quando cada
  um foi reiniciado pela última vez. Cai de volta no caminho antigo (relativo à release)
  quando não existe `shared/` (checkout local/dev sem o layout de release).
- Mitigação imediata: `shopvivaliz-queue-worker.service` reiniciado manualmente na VM.
  Confirmado que o job represado (`SV20260831182823370`) foi processado
  (`status: queued -> done`) e que, após o deploy da correção, tanto o lado
  web (webhooks) quanto o worker já escrevem no mesmo `shared/storage/queue.json`
  (validado às 16:12 UTC, com o worker preso a uma release diferente do `current` e ainda
  assim lendo/escrevendo o arquivo certo — a correção é resiliente mesmo sem reinício do
  worker a cada deploy).

## Pendente (não corrigido nesta rodada, decisão do dono do negócio)

- `deploy-production.sh` continua incapaz de rodar nesta VM por causa do worktree em
  `repo/.git`. Nenhum deploy "oficial" documentado alcança `shopvivaliz-free-a1`; o que
  publica código hoje é um mecanismo não identificado nesta investigação.
- `master-production-pipeline.yml` (e, pelo grep, uma fração grande dos 250+ workflows em
  `.github/workflows/`) ainda referencia a VM terminada `137.131.156.17`. Reescrever essa
  esteira de automação para a VM atual é um projeto à parte, fora do escopo desta
  investigação.
- Sem o restart automático do worker em cada deploy, o cenário permanece frágil: se a
  release à qual o worker está preso for apagada pela retenção antes de um restart manual,
  o worker vai falhar ao carregar o código (crash-loop) em vez de silenciosamente parar de
  processar. O `storage/queue.json` compartilhado elimina a perda silenciosa de dados, mas
  não substitui corrigir o restart automático.

## Conta de Google Ads (achados e ações reais)

- 3 campanhas `ENABLED` (nenhuma pausada): `ShopVivaliz-Search-Vedante-Rodo-Porta-2026`
  (ex-"Campaign #1", renomeada), `ShopVivaliz-Search-Vasos-Antique-Decore-2026-08`,
  `Ferramentas-Vasos-ROI10-ABC-2026-07` (sem tráfego).
- A campanha "Campaign #1" não era um erro: gera tráfego real e relevante para vedante/rodo
  de porta, aterrissando na página de produto certa. Foi renomeada e 5 keywords genéricas
  irrelevantes (`gratis`, `carro`, `usado`, `como fazer`, `janela`, broad match, zero
  tráfego) foram removidas — risco latente de gasto irrelevante, sem perda de performance
  real.
- Conversion tracking de Purchase (`Compra`, importado do GA4) já está corretamente
  configurado como `primary_for_goal=True`. Não era o problema.
- Recomendação `DYNAMIC_IMAGE_EXTENSION_OPT_IN` (imagens dinâmicas) não tem mutate
  disponível via API nesta versão (v24) — precisa ser ativada manualmente na UI do Google
  Ads (Configurações da conta -> Recursos criados automaticamente -> Imagens).
- Recomendações não aplicadas nesta rodada, por decisão consciente (envolvem gastar mais
  ou inventar copy de marketing sem validação do dono do negócio):
  `MARGINAL_ROI_CAMPAIGN_BUDGET`, `SEARCH_PARTNERS_OPT_IN`, `CALLOUT_ASSET`,
  `RESPONSIVE_SEARCH_AD_IMPROVE_AD_STRENGTH` (x2).
- Expansão de keywords para o catálogo completo (rodízios, puxadores, dobradiças, espelhos,
  vidros) foi deliberadamente **não feita** nesta rodada: gastar mais em tráfego antes de
  confirmar, com uma venda real, que o pipeline de pagamento consertado está de fato
  aprovando pedidos seria prematuro.

## Próximo passo recomendado

Acompanhar o banco de produção (`orders`, `order_status = 'pagamento_aprovado'`) nos
próximos dias. O primeiro pagamento aprovado pós-correção é a prova real de que o pipeline
está funcionando de novo — só depois disso faz sentido expandir keywords ou budget.
