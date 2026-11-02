# Solicitação nova de autorização humana — controller + bridge

Status: **PREPARAÇÃO — não aprovar até leitura de evidências finais e checks atuais.**
Esta solicitação substitui a PR #3499 como prova humana de autorização: #3499
foi integrada por `github-actions[bot]` às 02:26:56Z de 2026-11-02,
**sem qualquer review**. O grant 3499 não é válido e não autoriza runtime.

## O que o proprietário autorizará

- Identidade humana exclusiva: `shopvivaliz-human`, review `APPROVED` **no
  HEAD exato desta nova PR** e **merge manual pelo próprio proprietário**.
- Alvo: somente `always-free-arm-1787907847-26` (backend/controller).
- Principal técnico: `shopvivaliz-central-mcp`, sem shell genérico.
- Operação exata: `continuity_release_install`; capacidades
  `controller_promote` e `continuity_bridge_install`.
- Fonte técnica: PR #3498, merge commit
  `ae7857ee1187686a0b75bb0bd2e370a297910f60`; no instante do uso
  a release deve corresponder ao **HEAD atual do origin/main**, e este commit
  histórico deverá ser ancestral. Nunca promover SHA desatualizado.
- Prazo curto: até 2026-11-09T00:00:00Z, salvo revogação anterior.

## Guardrails imutáveis (negação se ausentes)

1. Verificador `scripts/validate-owner-pr-grant.py` consulta GitHub API
   autenticada para review humana no SHA exato, merger humano, grant introduzido
   nesta PR, integridade no main e validade. Nenhum review por bot é aceito.
2. `scripts/continuity/owner_authorized_rollout.py` confere campos e
   capacidades exatos, identidade Unix ubuntu e host backend, worktree limpa
   imutável, SHA GitHub remoto exato, compatibilidade com PR #3498.
3. O controller só pode usar o instalador canônico sob lease próprio de
   manutenção/fencing obtido por `controller-recovery-with-lease.py`.
4. O bridge é instalado em segunda fase sob **outro** maintenance runtime lease,
   fencing verificado, com hash SHA256 do worker e health do systemd.
5. Lock ativo de outro operador/durable recovery **não é forçado, liberado,
   encurtado ou substituído**. Nenhum deploy é feito pelo ato de aprovar/mergear.
6. `continuity_ready`, UI real ChatGPT autenticada, dispatcher, heartbeat e
   E2E de conversa vinculada ainda deverão ser observados **pós-implantação**.
   Sem isso a rotina não pode ser declarada APTO em produção.

## Trilha de execução após autorização

Operador autenticado no backend valida main no GitHub e Git local, prepara
worktree `--detach` limpa do SHA corrente e executa, como `ubuntu`,
`python3 scripts/continuity/owner_authorized_rollout.py
--release-dir <immutable-worktree> --main-sha <exact-remote-main-sha>
--grant-pr <numero-desta-PR> --grant-head-sha <head-aprovado>
--grant-merge-sha <merge-commit-pelo-humano>`.
O comando não imprime segredo. Os argumentos SHA só podem ser preenchidos a
partir da API GitHub oficial e readback de main no instante da operação.

## Riscos e rollback

Riscos: colisão de tarefas, interrupção do browser autenticado, instalação
parcial e green falso de continuidade. A prevenção é fail-closed para review,
main/artefatos, owner, lock/fencing e daemon. Rollback é via release anterior
imutável sob nova lease válida e mecanismo canônico, nunca editar live
`current` nem apagar lock alheio. Se o provedor negar RBAC/MFA ou sessão
corporativa continuar desconectada, preservar `BLOCKED_EXTERNAL`/checkpoint,
sem contornar a recusa e sem simular E2E.

## Dependências técnicas antes da aprovação humana

- PR #3500 integrada (bloqueia merge de autorização por bot): concluída em
  `63d65b87b111a31a08e25f1e5c305156e9d6830f`.
- PR #3502 de adapter/lease/guardrails: só considerar pronta após todos os
  checks pertinentes verdes e integração válida no main.
- Verificações desta PR de grant atual em GREEN e review solicitada nominalmente
  a `shopvivaliz-human`. Sem review ainda: **AWAITING_OWNER_APPROVAL**.
