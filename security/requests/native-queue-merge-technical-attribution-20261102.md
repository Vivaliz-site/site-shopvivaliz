# P2: comprovacao da fila nativa quando GitHub atribui merge ao enqueuer tecnico

Pedido de autorizacao formal para review no SHA exato de shopvivaliz-human.
Nao solicitar APPROVED sobre SHA alterado. O escopo e corrigir o
autorizador, nao conceder nova API nem refazer a aprovacao ja usada.

## Reproducao real, 2026-11-02

- PR #3554: shopvivaliz-human deu APPROVED no SHA
  c825483494c15d876018fa9a2a7590cc35cf79e4 as 20:14:18 UTC.
- A fila oficial registrou AddedToMergeQueueEvent em 20:21:43 UTC,
  enqueuer=fredmourao-ai no mesmo HEAD.
- GitHub executou Mandatory Validation Gate e Repository Governance
  em merge_group no SHA sintetico
  e5cb3896742418c462b1272b72802c5499c924eb, ambos SUCCESS
  pelo GitHub Actions (app 15368).
- A fila concluiu o merge 20:29:46 UTC, HEAD aprovado imutavel.
  REST atribuiu merged_by.login=fredmourao-ai, nao ao queue bot.
- O workflow #41519710001 falhou por negar a prova do grant #3554.
  O ruleset 24523478 ja exige fila nativa, strict=true, dois checks
  obrigatorios, bypass_actors=[].

## Contrato seguro proposto

Aceitar merged_by=fredmourao-ai SOMENTE no repositorio
Vivaliz-site/site-shopvivaliz e com TODOS os vinculos oficiais:
aprovacao humana APPROVED no HEAD exato sem revogacao posterior,
autor distinto, grant versionado vigente e escopo especifico,
AddedToMergeQueueEvent no mesmo HEAD pelo tecnico APOS aprovacao e
ANTES do merge, merge SHA de workflow sintetico merge_group real,
ambos workflows e checks GitHub Actions obrigatorios verdes no SHA final.
Nao aceitar merge tecnico direto, status ficticio, evento antigo,
SHA distinto ou conta nao autorizada. O merge manual humano historico
continua sujeito as regras originais.

Ao observar fila ja ativa, ativacao apenas valida em modo leitura:
ALLGREEN, SQUASH, um build, strict=true, mesmos checks, nenhuma
excecao de bypass e demais protecoes, e sai idempotentemente
sem exigir novo grant para um NO-OP. Se fila nao existe, exige
grant P2 antes de qualquer mutacao. Nunca rebaixar strict=false.

Rollback: reverter somente codigo deste PR, sem desativar a fila.
Token de workflow ganha somente actions:read e checks:read, nunca
poder de escrita; a identidade tecnica independente continua isolada.
