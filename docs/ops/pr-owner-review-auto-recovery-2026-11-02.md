# Recuperacao automatica do check anterior a aprovacao humana

## Incidente observado

Na PR #3560, o job original `125615374820` do run `41544120846`
falhou antes da aprovacao, por `owner_approval_missing`. A revisao humana
real aprovou o HEAD `ec0eefecd4c679193d87d34cff3fdd0c40e5e707`.
O run de review `41544499828` passou, mas o check original continuou
vermelho no rollup e o enfileirador terminou com `CalledProcessError`.

A recuperacao executada foi repetir somente o job original pela API
oficial, sem alterar HEAD, revisao, checks ou branch protection. A segunda
tentativa passou. O run de fila `41545985993` confirmou o enfileiramento
com o HEAD aprovado; a fila nativa integrou a PR em
`26e2caf262e9bc25b5d26f8e1dd43633312dd205`, as 23:35:36 UTC.
Isso comprova a recuperacao deste caso, nao autoriza ativacao de root.

## Prevencao na rotina existente

O PR Completion Enforcer passa a recuperar o job original antes de
encaminhar PRs protegidas ao fluxo exclusivo da fila nativa. O helper
usa o verificador de aprovacao ja revisado, sem mudar sua politica.

A recuperacao exige PR aberta e pronta, mesmo repositorio e base main,
HEAD exato, aprovacao real do proprietario e ausencia de hold de runtime
ou bloqueio externo. Exige ainda o ultimo run original de pull_request
da Mandatory Validation Gate, tentativa 1, falha somente no passo de
aprovacao, log com motivo conhecido anterior a aprovacao e check do app
oficial GitHub Actions. Rele novamente PR, review e run antes do POST.

Somente um job e repetido por rodada e nunca uma segunda tentativa.
Sucesso ou execucao em andamento mais recente impede replay historico.
Nenhum erro de teste, de API, de credencial ou de outra politica provoca
retry cego. Falha de leitura fica explicita e nao se transforma em sucesso.
O label no-auto-merge continua impedindo merge pelo finalizador; recuperar
um check nao e realizar merge nem conceder privilegios.

Eventos de review e runs originais podem omitir pull_requests. Nesses casos, o finalizador
resolve apenas uma PR aberta inequivoca associada ao HEAD exato, sem
buscar por nome de branch nem trabalhar em SHA antigo.

## Latencia

O lint PHP em pull_request_review agora usa o mesmo diff do evento
pull_request. Validacao completa de CI continua cobrindo todos os PHP
rastreados, mas nao revarre checkouts auxiliares nao rastreados como
trusted-review e trusted-owner-gate. Merge_group, suites universais e
verificacao de aprovacao continuam obrigatorios.

No run observado, havia cerca de 155 segundos entre composer validate e
composer install, correspondentes ao lint recursivo anterior. Essa medida
nao e uma promessa de tempo fixo: fila, testes e runner tambem influenciam.

## Validacao e limites

28 testes locais: seletor de lint com historicos Git reais; negativos de
revisao, SHA, hold, app, motivo de falha e corrida; idempotencia; e execucao
do shell real do finalizador com somente a API GitHub substituida por
respostas locais. Regressao RED comprovada antes da implementacao, seguida
de GREEN. Os testes entram no gate Linux existente. Validar tambem os
checks oficiais da PR e do merge_group antes da integracao.

Sem novo daemon, credenciais, root, DNS, grants, revisores obrigatorios
universais, merge direto de P2 ou alteracao de branch aprovada.
Rollback: reverter esta PR tecnica; o fluxo anterior de fila e os controles
de aprovacao permanecem intactos.
