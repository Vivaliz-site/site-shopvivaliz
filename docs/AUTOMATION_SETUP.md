# Configuracao de automacao ShopVivaliz

Este guia descreve como preparar o repositorio para concluir a automacao de atualizacoes sem registrar dados sensiveis em arquivos versionados.

## Objetivo

Transformar o pacote de agentes v9.2.84 em uma rotina de deploy e validacao controlada pelo GitHub Actions e pelo watchdog residente do ShopVivaliz.

## Componentes

- Workflow de QA para validar PHP e ausencia de padroes sensiveis, incluindo execução de testes unitários.
- Workflow de pacote para gerar artefato da v9.2.84.
- Script local [`scripts/local-artifact-builder.py`](scripts/local-artifact-builder.py) para gerar ZIPs cumulativos manualmente.
- Agente watchdog para rodar reparos, self-test, imagens Olist e relatorio.
- Motor de ROI para priorizar conversao e crescimento sem alterar precos, descontos ou margem.
- Script [`scripts/log-health-checker.py`](scripts/log-health-checker.py) para auditoria de saúde dos logs.
- Script [`scripts/log-simulator.py`](scripts/log-simulator.py) para gerar dados de log para testes.
- Arquivo [`scripts/sales-metrics.json`](scripts/sales-metrics.json) como base minima de vendas por produto.
- Script [`scripts/roi-engine.php`](scripts/roi-engine.php) para consolidar prioridades ROI a partir de vendas, ciclos autonomos e fontes opcionais de marketplace.
- Otimização de custos de IA através da seleção de modelos (e.g., `gemini-2.5-flash`, `claude-3-haiku-20240307`).
- Endpoint de relatorio para acompanhamento pelo ChatGPT.
- Handoff pos-update para disparo automatico apos deploy.

## Configuracao externa necessaria

A configuracao de acesso ao servidor deve ficar somente nas configuracoes protegidas do GitHub ou no painel do provedor. Nenhum valor real deve ser escrito no repositorio.

## Fluxo operacional

1. Merge do PR da v9.2.84.
2. Execucao do QA.
3. Geracao do artefato de pacote.
4. Envio do pacote ao servidor pelo mecanismo de deploy configurado.
5. Execucao do handoff pos-update.
6. Execucao do motor de ROI e consulta ao relatorio autonomo.

## Confirmacoes esperadas

- QA aprovado.
- Artefato gerado.
- Endpoint de relatorio respondendo JSON.
- Heartbeats recentes dos agentes.
- Produtos Olist com imagens sendo corrigidas progressivamente.
