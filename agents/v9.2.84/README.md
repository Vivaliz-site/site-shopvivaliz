# ShopVivaliz v9.2.84 - Autonomous Report Agents

Pacote de agentes residentes para iniciar, monitorar e relatar os ciclos autonomos do ShopVivaliz.

## Objetivo

Corrigir o ponto observado na v9.2.83: o motor do loop existe, mas o relatorio retornou `cycles_total: 0` e `last_report: null`. Esta versao adiciona agentes independentes para gerar diagnostico, heartbeat e disparo controlado do loop.

## Agentes incluidos

1. `ShopvivalizAutonomousReportAgent` - consolida versao, ciclos, logs, self-test, status de imagens Olist e tabelas criticas.
2. `ShopvivalizAutonomousWatchdogAgent` - executa heartbeat, reparos seguros, tentativa de start do loop e relatorio final.
3. `ShopvivalizSafeMigrationRepairAgent` - cria tabelas/indices de controle sem quebrar em execucoes repetidas.
4. `ShopvivalizOlistImageRepairAgent` - reconta imagens, define imagem primaria e corrige vinculos basicos produto/imagem.
5. `api/agent/autonomous-report.php` - endpoint JSON para enviar relatorio ao ChatGPT.
6. `api/agent/autonomous-watchdog.php` - endpoint JSON para iniciar ciclo watchdog.
7. `installer/agent-handoff.php` - handoff pos-update para chamar watchdog automaticamente apos deploy.

## Como usar no deploy

Copie o conteudo deste pacote para a raiz do ShopVivaliz mantendo os caminhos:

- `app/AutonomousReportAgent.php`
- `app/AutonomousWatchdogAgent.php`
- `app/SafeMigrationRepairAgent.php`
- `app/OlistImageRepairAgent.php`
- `api/agent/autonomous-report.php`
- `api/agent/autonomous-watchdog.php`
- `installer/agent-handoff.php`

Depois do deploy, chamar:

```text
/api/agent/autonomous-report.php
```

para obter o relatorio, ou:

```text
/api/agent/autonomous-watchdog.php?run_loop=1&cycles=3
```

para executar o watchdog. O endpoint aceita token opcional via `agent_key`, `key` ou header `X-Shopvivaliz-Agent-Key` se o site ja tiver chave configurada.

## Status

Revisao de pacote disparada para gerar artefato v9.2.84 pelo GitHub Actions.

## Notas de seguranca

- Nenhuma credencial foi colocada no codigo.
- O watchdog usa lock para evitar execucao paralela.
- Reparos SQL sao idempotentes e toleram indice/tabela ja existente.
- O loop e limitado por parametros para evitar timeout.
