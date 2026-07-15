# Atualizacoes autonomas ShopVivaliz

Este repositorio passa a servir como base de apoio para atualizacoes autonomas do ShopVivaliz.

## Fluxo recomendado

1. Criar ou atualizar pacote em `agents/v9.2.84`.
2. GitHub Actions gera artefato versionado.
3. Deploy copia os arquivos para a raiz do site.
4. O pos-update chama `installer/agent-handoff.php`.
5. O watchdog executa reparos, self-test, Olist/imagens, pedido de loop e relatorio.
6. O relatorio fica disponivel em `api/agent/autonomous-report.php`.

## Agentes reais

- `SafeMigrationRepairAgent`: cria tabelas e indices de controle sem falhar em repeticao.
- `OlistImageRepairAgent`: corrige contagem e imagem primaria dos produtos Olist.
- `SelfTestAgent`: valida PHP, extensoes e pasta storage.
- `LoopStarterAgent`: registra pedido de execucao do loop autonomo.
- `AutonomousReportAgent`: gera relatorio para acompanhamento.
- `AutonomousWatchdogAgent`: orquestra todos os agentes.

## Endpoints apos deploy

- `/api/agent/autonomous-report.php`
- `/api/agent/autonomous-watchdog.php`
- `/installer/agent-handoff.php`

## Regra operacional

Nenhum segredo deve ser commitado. Tokens, FTP, APIs e chaves continuam fora do repositorio, em variaveis de ambiente, painel do servidor ou configuracao existente do ShopVivaliz.
