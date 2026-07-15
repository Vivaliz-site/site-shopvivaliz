# ShopVivaliz v9.2.84 - Criterios de pronto

## Nivel 1 - Repositorio pronto

- PR aberto contra `main`.
- QA verde.
- Manifesto atualizado.
- Agentes presentes no pacote.
- Sem credenciais reais no codigo.

## Nivel 2 - Deploy aplicado

- Arquivos da pasta `agents/v9.2.84` copiados para a raiz do site conforme manifesto.
- Migration executada sem erro fatal.
- `/api/agent/autonomous-report.php` responde JSON.
- `/api/agent/media-quality.php` responde JSON.
- `/installer/sync-runner.php` responde JSON.

## Nivel 3 - Agentes residentes funcionando

- `/api/agent/autonomous-watchdog.php?run_loop=1&cycles=3` responde JSON.
- Tabela `sv_agent_heartbeats` recebe eventos.
- Relatorio mostra ciclos ou pedidos de ciclo recentes.
- Auditoria de midia mostra contadores coerentes.
- Reparos de vinculo e imagem podem ser repetidos sem quebrar.

## Nivel 4 - Automacao externa ligada

Pelo menos um orquestrador chamando periodicamente:

- `/api/agent/external-trigger.php?task=report`
- `/api/agent/external-trigger.php?task=media`

Opcoes aceitas:

- cron no servidor;
- UptimeRobot;
- n8n;
- Make;
- Pipedream;
- GitHub workflow manual ou self-hosted runner.

## Nivel 5 - Autonomia validada

- Atualizacao pode ser aplicada sem abrir painel.
- Relatorio final pode ser coletado em JSON.
- Falhas ficam registradas em heartbeat ou relatorio.
- Imagens/midias sao auditadas apos cada ciclo.
