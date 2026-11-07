# Browser tab reaper

Policy atualizada e expressamente aprovada pelo proprietario (2026-11-06):
a limpeza automatica das sessoes de navegador nao deve ser desativada. Na
instalacao ativa de backend, o servico usa tres browsers isolados (Dev 9559,
Atendimento 9556, Dev auxiliar 9557), `--idle-hours 24`,
`--keep-min 5`, `--max-closes 2` e `--apply`. Preserva paginas de
login e conversas vinculadas a tarefas ativas. O login reside no perfil,
nao numa aba. Os valores de 1 hora/1 aba/10 fechamentos descritos nos
defaults do script nao substituem esta configuracao autorizada.

`scripts/browser_tab_reaper.py` closes every tab that is not in use in the backend agent
browsers (`dev` 9559, `atendimento` 9556, `dev-aux` 9557). It is a **dry run by default**.

## When a tab is closed

All of these must hold:

- it is a normal page (not `chrome://`, devtools, an extension, or a login page on
  `auth.openai.com`, `accounts.google.com`, `login.microsoftonline.com`, `appleid.apple.com`);
- its ChatGPT conversation is **not** bound (`bind-conversation`) to a task whose status is
  `RUNNING` or `READY_TO_COMPLETE` in the agent task-state directory (work in progress);
- for a tab **outside ChatGPT**, no `RUNNING`/`READY_TO_COMPLETE` task that made progress
  within `--task-fresh-hours` (default 6) is bound to that browser session (`dev` covers the
  `dev` and `dev-aux` browsers, `atendimento` covers its own). A task may be typing in a form
  or SPA, which leaves URL and title unchanged, so such a tab cannot be shown idle. Progress
  is the checkpoint's logical `updated_at`, advanced by every real step. File mtime is only a
  fallback when `updated_at` is missing or unreadable, because recovery bookkeeping rewrites
  the file without advancing `updated_at`. The window keeps an abandoned checkpoint left
  `RUNNING` from disabling the reaper for that browser forever. A conversation binding (one specific tab) has no time limit. Unbound ChatGPT
  tabs, the bulk of the accumulation, are still closed;
- its URL and title have not changed for `--idle-hours` (default 1), counted from the
  reaper's own first observation. CDP exposes no last-activity time, so a tab seen for the
  first time always starts a new clock and cannot be closed on that run. The grace only
  protects a tab an agent just opened and has not bound to a task yet;
- the browser keeps at least `--keep-min` tabs (default 1, the most recently changed one),
  and at most `--max-closes` (default 10) tabs are closed per browser per run, so a
  browser with many tabs converges to one over a few hourly runs.

Fail closed: if the task-state directory is missing or contains an unreadable file, **no tab
is closed** (the reaper cannot tell what is in use) and the run exits 3.

The report prints host names and counts only. It never prints URLs, titles or conversation
identifiers. Only loopback CDP endpoints are used. The state file
(`~/.local/state/shopvivaliz/browser-tab-reaper.json`) is mode 0600.

## What it does not do

It does not stop browser processes or services, restart anything, or touch the Docker
workloads of other projects. Profiles that should not exist at all are a separate decision.

## Agendamento horario resiliente a reinicio (2026-11-07)

O timer anterior combinava `OnBootSec=15min` com `OnUnitActiveSec=1h`.
Apos parada e reativacao do timer em 07/11, `systemctl` reportava
`ActiveState=active`, mas `SubState=elapsed`,
`NextElapseUSecMonotonic=infinity` e proxima execucao em branco.
O servico havia executado com sucesso em 00:12 UTC e nao retomou
automaticamente depois de 01:50 UTC. Isto e **falha de recorrencia**,
nao justificativa para desabilitar a limpeza.

A unidade canonica agora usa `OnCalendar=hourly` e `Persistent=true`.
No backend, foi aplicada uma sobreposicao reversivel que limpa os
gatilhos monotonicamente expirados e configura o calendario, sem mudar
o `ExecStart` autorizado. Depois do `daemon-reload` e restart do
timer, ocorreu um ciclo em 03:15:18 UTC com `Result=success` e
`ExecMainStatus=0`; o timer permaneceu `active/waiting` e anunciou
`NextElapseUSecRealtime=2026-11-07 04:00:00 UTC`. Evidencias MCP:
`d14c3285-1230-43bd-a419-3debd77bceaf` (aplicacao) e
`03a892f6-2dba-4a59-afeb-0e3ed25fb6a6` (leitura independente).
A rotina so fecha abas observadas inativas por pelo menos 24 horas.

Apos reinstalar a unidade a partir deste repositorio, verificar com
`systemctl show shopvivaliz-browser-tab-reaper.timer -p ActiveState -p SubState -p NextElapseUSecRealtime`.
`active/elapsed` com proxima execucao vazia **nao** equivale a
agendamento valido. O drop-in de calendario do runtime pode permanecer
ate que a unidade canonica atualizada seja instalada; confirmar que
nao existe gatilho duplicado depois da consolidacao.

## Install (manual, not done by merging this file)

```bash
sudo install -m 644 deploy/systemd/shopvivaliz-browser-tab-reaper.service /etc/systemd/system/
sudo install -m 644 deploy/systemd/shopvivaliz-browser-tab-reaper.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now shopvivaliz-browser-tab-reaper.timer
```

Review dry-run output first:

```bash
journalctl -u shopvivaliz-browser-tab-reaper.service -n 20 --no-pager -o cat
```

## Enable closing, and roll back

```bash
echo 'SHOPVIVALIZ_TAB_REAPER_APPLY=1' | sudo tee /etc/default/shopvivaliz-browser-tab-reaper
# Para instalacoes legadas sem ExecStart sobreposto, modo dry run:
sudo rm /etc/default/shopvivaliz-browser-tab-reaper
# OBSERVACAO: no backend atual o ExecStart aprovado tem --apply explicito;
# remover o arquivo de ambiente NAO o desativa. A alteracao do grant ou da
# rotina exige aprovacao do proprietario, nao constitui reparo de timer.
```

Only the value `1` enables closing. Closed ChatGPT tabs can be reopened from the server-side
conversation history; this tool never deletes a conversation.
