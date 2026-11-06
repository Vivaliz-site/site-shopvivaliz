# Registro de aposentadoria da plataforma paralela Medusa

Data: 2026-11-06. O proprietario solicitou excluir Medusa do ambiente
operacional. O pacote MedusaJS/Next.js ja havia sido removido pela PR #740
(2026-08-03), mas permaneceram arquivos executaveis, relatorios estaticos,
paineis com instrucoes de retomada e um webhook de retorno 200 sem acao real.

## Alteracoes executadas

- Remocao do webhook Medusa.
- Remocao dos antigos sincronizadores Olist e Shopee que publicavam em Medusa.
- Remocao do webhook antigo em claude/api/olist/webhook.php, que invocava um
  metodo inexistente e ainda assim retornava HTTP 200.
- Remocao dos dois scripts PowerShell dedicados e dos dois relatorios de migracao.
- Remocao das instrucoes e indicadores de migracao dos paineis Claude e README.
- Preservacao do webhook oficial em api/olist/webhook.php e de todos os
  demais conectores ERP/marketplaces.

## Preservacao e verificacao

Historico Git nao foi reescrito e os relatorios antigos continuam como prova
historica, nunca como tarefa ativa. Credenciais, bancos e dados de clientes nao
foram alterados. Os gates de aceite incluem lint PHP, teste de ausencia,
contratos do Olist e comprovacao da release ativa na Marina.

Tambem foram retiradas as declaracoes Medusa dos relatorios operacionais de
auditoria e a excecao Medusa do diagnostico de CI, com teste de regressao.
