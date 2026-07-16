# ShopVivaliz v9.2.84 - Runtime endpoints

Esta atualizacao adiciona agentes residentes para reduzir dependencia de execucao manual.

## Endpoints criados

### Relatorio autonomo

`/api/agent/autonomous-report.php`

Retorna JSON com estado dos ciclos, heartbeats e ultimos eventos conhecidos.

### Watchdog autonomo

`/api/agent/autonomous-watchdog.php`

Executa reparos seguros, self-test, pedido de loop e relatorio.

Parametros uteis:

- `run_loop=1`
- `cycles=3`
- `chunk_size=10`

### Auditoria de midia

`/api/agent/media-quality.php`

Retorna indicadores de produtos com midia principal, produtos pendentes, midias ativas, midias sem vinculo e produtos com galerias extensas.

### Gatilho externo

`/api/agent/external-trigger.php`

Parametros:

- `task=report`: relatorio autonomo.
- `task=media`: auditoria de midia.

Pode ser chamado por cron, UptimeRobot, n8n, Make, Pipedream ou outro orquestrador.

### Sync runner

`/installer/sync-runner.php`

Executa o agente de pull controlado pelo servidor.

Modos:

- sem parametros: prepara pacote em `storage/github-pull/v9.2.84`.
- `apply=1`: aplica arquivos baixados na raiz do site.
- `handoff=1`: tenta chamar handoff apos aplicacao.

## Sequencia recomendada apos deploy

1. Chamar `/installer/sync-runner.php` para validar download.
2. Chamar `/installer/sync-runner.php?apply=1` para aplicar.
3. Chamar `/installer/sync-runner.php?apply=1&handoff=1` para aplicar e executar handoff.
4. Chamar `/api/agent/media-quality.php` para auditar midia.
5. Chamar `/api/agent/autonomous-report.php` para registrar relatorio final.

## Observacao de seguranca

Nao colocar credenciais reais em arquivos do repositorio. Variaveis de ambiente e secrets devem ficar no servidor ou no provedor de automacao.
