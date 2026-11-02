# Operação: uma aprovação humana até o merge

A PR P2 de security/requests/owner-review-merge-queue-20261102.md
requer review oficial APPROVED no HEAD exato e merge pessoal pelo
proprietário durante a transição. A rotina horária idempotente consulta
o manifesto config/owner-review-merge-queue.json, verifica a aprovação
e acrescenta a regra merge_queue ao ruleset principal 24523478.
Nenhuma mudança ocorre antes da autorização formal.

O usuário então só registra APPROVED em shopvivaliz-human.
O workflow de revisão valida o SHA com GitHub, checks obrigatórios,
escopo sensível, ausência de bloqueio de lease e ruleset ativo; envia
enqueuePullRequest com identidade técnica fredmourao-ai. O backend
não modifica o HEAD depois do review, nem habilita auto-merge como
usuário humano, nem aciona merge direto ou --admin.

GitHub cria merge_group sobre main recente. Mandatory Validation Gate
e Repository Governance testam a combinação e mantêm seus contextos
required sem qualquer alteração. O GitHub merge-queue bot integra
somente se todos gates oficiais passam. O script de autorização do
MCP exige prova imutável da revisão humana no HEAD, prova GraphQL
da fila e todos os controles existentes para reconhecer o grant.

Falha de conflito/CI que exigiria mudar HEAD deve ser corrigida
ANTES de pedir a aprovação humana. Mudança depois do review requer
nova aprovação, por segurança; nenhum executor pode forjar consentimento.
Falha de queue não equivale a autorização para desligar branch
protection, usar --admin ou criar um bypass.
