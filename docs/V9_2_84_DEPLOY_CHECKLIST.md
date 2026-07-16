# Checklist deploy v9.2.84 - Autonomous Agents

## Antes do deploy

- Confirmar que o PR v9.2.84 passou no workflow de QA.
- Confirmar que o workflow de pacote gerou artefato.
- Confirmar ausencia de credenciais no diff.
- Confirmar que a versao e cumulativa.

## Arquivos a copiar para a raiz do ShopVivaliz

- `app/*.php` do pacote v9.2.84
- `api/agent/*.php` do pacote v9.2.84
- `installer/agent-handoff.php`
- `database/migrations/20260625_9284_autonomous_report_agents.sql`

## Pos-deploy

1. Executar migrations pelo atualizador existente.
2. Chamar `installer/agent-handoff.php`.
3. Consultar `api/agent/autonomous-report.php`.
4. Confirmar que o relatorio mostra heartbeats recentes.
5. Confirmar que `sv_autonomous_loop_requests` recebeu pedido de loop.
6. Confirmar que produtos Olist possuem imagens primarias progressivamente.

## Sinais de sucesso

- `database_available=true` no relatorio.
- `sv_agent_heartbeats.rows > 0`.
- `olist_images.products_with_image` aumentando.
- `sv_autonomous_loop_requests.rows > 0` quando watchdog roda com `run_loop=1`.
- Nenhum erro PHP no endpoint.

## Sinais de atencao

- `database_available=false`: verificar carregamento de `app/Support.php` e helper PDO.
- `cycles_total=0`: loop da v9.2.83 ainda nao foi consumido; manter `LoopStarterAgent` ativo.
- Produtos sem imagem: manter rotinas Olist/imagens em execucao.
