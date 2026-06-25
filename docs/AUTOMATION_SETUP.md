# Configuracao de automacao ShopVivaliz

Este guia descreve como preparar o repositorio para concluir a automacao de atualizacoes sem registrar dados sensiveis em arquivos versionados.

## Objetivo

Transformar o pacote de agentes v9.2.84 em uma rotina de deploy e validacao controlada pelo GitHub Actions e pelo watchdog residente do ShopVivaliz.

## Componentes

- Workflow de QA para validar PHP e ausencia de padroes sensiveis.
- Workflow de pacote para gerar artefato da v9.2.84.
- Agente watchdog para rodar reparos, self-test, imagens Olist e relatorio.
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
6. Consulta ao relatorio autonomo.

## Confirmacoes esperadas

- QA aprovado.
- Artefato gerado.
- Endpoint de relatorio respondendo JSON.
- Heartbeats recentes dos agentes.
- Produtos Olist com imagens sendo corrigidas progressivamente.
