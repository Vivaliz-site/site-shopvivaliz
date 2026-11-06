# Autorização humana substitutiva — continuidade de tarefas, PR #3725

## Motivo da reemissão

A PR técnica [#3725](https://github.com/Vivaliz-site/site-shopvivaliz/pull/3725)
foi mesclada em 2026-11-06T05:22:03Z, commit
`cb4eb873e1caf32b47106b256ed0c6356f12e373`, **sem evento GitHub
APPROVED da identidade `shopvivaliz-human`**, embora a revisão tivesse
sido solicitada. A solicitação não é uma aprovação. O merge de código
não autoriza ativar o novo executor de recuperação desacoplada.

Esta PR é um **novo pedido formal**, não uma atribuição retroativa de aprovação
à #3725. Ela exige revisão nominal `shopvivaliz-human` no seu próprio HEAD
e merge humano ou auto-merge nativo habilitado exclusivamente pelo proprietário.

## Escopo da decisão

- Host: `always-free-arm-1787907847-26`, controlador de continuidade.
- Componente: `scripts/agent_task_state.py` / watchdog / dispatcher.
- Tarefa alvo: `v5-cert-marina-20261106`, já existente, iniciada por
  `claude-sonnet-5-5`, sem conversa ChatGPT comprovada.
- Capacidade solicitada: autorizar **uma única transição explícita**
  `authorize-detached` no checkpoint original, mantendo mesma tarefa,
  versão CAS, estado e checklist; posteriormente executar tentativa finita
  por rota `cli` sob runtime lease/fencing.
- Nenhuma ampliação de host, shell irrestrito, troca de conta ChatGPT,
  credencial, bypass de autenticação ou liberação de lease de terceiros.
- O controlador continua na revisão anterior até merge humano **e**
  verificação das condições do grant. A release instalada deve corresponder
  ao `main` então vigente, com controle de SHA e instalador imutável.

## Gates para a execução após aprovação

1. Atestar `APPROVED` feito por `shopvivaliz-human` no HEAD **desta PR**,
   conferir grant imutável e merge pelo fluxo oficial permitido.
2. Verificar origem `agent_id`, `task_id`, checklist, checkpoint_version
   e ausência de binding conflitante imediatamente antes de autorizar rota.
3. Verificar runtime lock livre, aquisição exclusivamente oficial de lease,
   fencing, expiração e saída auditada; não apagar ou assumir leases vivos.
4. Promover apenas release imutável do `main` validado e rodar testes GREEN.
5. Realizar E2E de retomada real na tarefa V5, exigindo progresso
   material verificável, interação na UI da aplicação, provas e revisão
   independente; a PR não certifica `APTO`.
6. Se algum gate falhar, deixar checkpoint RUNNING e grant inativo, com
   trilha de erro e próximo passo; não fabricar PROGRESS_CONFIRMED.

## Prevenção e rollback

A rotina `scripts/merge_green_pr_via_vm.sh` passa a recusar PR com
`requested_reviewers=shopvivaliz-human`, além de continuar recusando
`security/requests/*`, `security/owner-approvals/*` e `no-auto-merge`.
Teste regressivo executa `gh` simulado e confirma que nenhum PUT de merge
é chamado quando a revisão humana está pendente.

Rollback: reverter a alteração do finalizador por PR autorizada;
para runtime, restaurar release anterior somente pelo mecanismo oficial
com lease e fencing próprios. **Não ativar a retomada desacoplada
sem a autorização aprovada.**
