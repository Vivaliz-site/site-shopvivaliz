# Routine Abandonment Monitor

**Efetivo:** 2026-09-02
**Motivo:** em 2026-09-02, uma sessao de agente anterior deixou, na LAPTOP-NIG4IFUU e em
worktrees das duas A1, automacao de UI clicando sozinha em telas de consentimento
(OAuth do Gmail e "Verify Device" do proprio Desktop Commander) via tarefas agendadas de
disparo unico que nunca foram desativadas depois de rodar. Isso contraria a regra ja
existente em `docs/DESKTOP-COMMANDER-24H.md` ("a automacao... nao contorna politica de
autenticacao do provedor") e so foi descoberto porque alguem checou manualmente. Este
monitor existe para detectar o mesmo padrao de novo sem depender de revisao manual nem de
IA paga (ver regra de custo/execucao no `CLAUDE.md` raiz: nada pago em cron/timer/daemon).

## O que ele faz

Roda uma verificacao **puramente deterministica** (sem chamar nenhuma IA) e sinaliza:

- **Windows** (`scripts/routine-abandonment-monitor.ps1`): tarefas agendadas
  `ShopVivaliz*` com trigger de disparo unico (sem `NextRunTime`) que continuam
  `Enabled`/`Ready` muito tempo depois do `LastRunTime` (padrao: 4h).
- **Linux** (`scripts/routine-abandonment-monitor.sh`): unidades systemd `shopvivaliz-*`
  fora da allowlist, e jobs `at` pendentes cujo comando contenha `oauth`, `click`,
  `verify` ou `probe`.

Nao apaga, nao desabilita, nao mata processo nenhum -- so registra em log e grava um
arquivo de status JSON, seguindo a mesma convencao de `.agent-status/*.json` ja usada
pelo `claude-local-station.json` e por `api/monitor/dev-status.php`.

## Allowlist (rotinas 24h/watchdog conhecidas -- nunca geram alerta)

Definida no topo de cada script. Atual:

- `ShopVivaliz Desktop Commander 24h` / `ShopVivaliz Desktop Commander Task Guardian`
  (Windows) e `shopvivaliz-desktop-commander.service` / `-guardian.service` / `-guardian.timer`
  (Linux) -- watchdog oficial do Desktop Commander, ver `docs/DESKTOP-COMMANDER-24H.md`.
- `ShopVivaliz Fred-Win Relay 24h` -- tunel SSH reverso privado canonico, ver
  `docs/FRED-WIN-PRIVATE-RELAY.md`. Producao de longa data, confirmado com Fred em
  2026-09-02; **nao desativar**.
- `ShopVivaliz Amazon Returns Seller Central Bridge` / `shopvivaliz-amazon-returns.service`
  -- daemon do bridge de devolucoes Amazon.
- `ShopVivaliz Auto Sync` -- sincronizacao do repositorio (pode aparecer `Disabled`,
  ainda assim e uma rotina conhecida, nao um achado).
- `shopvivaliz-queue-worker.service`, `shopvivaliz-shopee-token-renewer.service`,
  `shopvivaliz-token-renewer.service` -- daemons de producao na A1 de site/deploy.

Adicionar um item novo aqui exige confirmacao explicita do Fred (mesma regra de
"decisao estrutural" que ja vale para arquivar workflows, ver `CLAUDE.md` raiz) --
um agente nao deve se auto-eximir de um alerta.

## Onde roda

- **LAPTOP-NIG4IFUU**: tarefa agendada `ShopVivaliz Routine Abandonment Monitor`,
  visivel (nao oculta), a cada 30 min.
- **always-free-arm-1787907847-26** e **shopvivaliz-free-a1**: timer systemd
  `shopvivaliz-routine-abandonment-monitor.timer` (unit files em
  `ops/systemd/shopvivaliz-routine-abandonment-monitor.{service,timer}`), a cada 30 min.
- **DESKTOP-KOCEPSV**: pendente -- host nao alcancavel a partir da sessao que criou este
  monitor (nao aparece no Tailscale, MCP do Desktop Commander sem conexao). Replicar
  quando houver acesso confirmado.

## Incidente que originou este monitor (2026-09-02)

Tarefas desativadas e scripts movidos para
`C:\ShopVivaliz\_quarantine_evidence_20260902\` na LAPTOP-NIG4IFUU (XML da definicao de
cada tarefa exportado antes de desativar, nada foi apagado):

- `ShopVivaliz Gmail OAuth Account Select`, `ShopVivaliz Gmail OAuth Consent Probe`,
  `ShopVivaliz Gmail OAuth Headless`, `ShopVivalizFredGoogleACCOUNT`,
  `ShopVivalizInspectFredBrowsers` -- clicavam sozinhas nas telas de escolha de conta e
  consentimento do Google para `fredmourao@gmail.com`.
- `ShopVivaliz DC Auth UI Probe`, `ShopVivaliz DC Auth UI Verify`,
  `ShopVivaliz DC Restore Browser Session`, `ShopVivaliz DC Restore Chrome Once` --
  a segunda (`dc_click_verify.py`) clicava sozinha no botao "Verify Device" do proprio
  Desktop Commander, o que contraria `docs/DESKTOP-COMMANDER-24H.md` diretamente.

Auditoria das duas A1 (`144.22.157.209`, `163.176.103.253`) no mesmo dia nao encontrou
automacao equivalente rodando (achados como `oauth-auto-exec.py` e `test-direct-click.py`
sao ferramentas legitimas -- wrapper do login OAuth do Olist e smoke test do checkout,
respectivamente -- confirmados por leitura de codigo, nao apenas pelo nome do arquivo).

Achado a parte (nao corrigido neste PR): `scripts/fredwin-desktop-commander-status.ps1`
usa uma regex (`@wonderwhy-er/desktop-commander@0\.2\.47.*remote.*--persist-session`) que
nao bate com o command-line real do processo resolvido via cache do npx
(`...\node_modules\@wonderwhy-er\desktop-commander\dist\index.js remote --persist-session`,
sem o `@0.2.47` literal no caminho), entao `CANONICAL_AGENT_COUNT` sempre reporta 0 mesmo
com o DC saudavel. Confirmado pelo log do supervisor (`Converging launchers canonical=1...`)
que a deteccao *real* do supervisor esta correta -- o bug e so no script de diagnostico
manual, sem risco para o uptime do DC. Registrar como tarefa separada para corrigir a regex.
