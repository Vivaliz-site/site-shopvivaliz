# Reemissão de autorização humana — PR #3501

A PR #3499 foi integrada por github-actions[bot] sem nenhum review APPROVED e não confere autorização. Este PR introduz novo grant imutável security/owner-approvals/3501.json para revisão exclusiva de shopvivaliz-human e impede merge automatizado das PRs de autorização pela rotina Oracle.

## Escopo, mínimo privilégio e pré-condições
- Backend/controller: always-free-arm-1787907847-26.
- Código técnico de origem: merge da PR #3498, ae7857ee1187686a0b75bb0bd2e370a297910f60.
- Antes de promover, verificar o SHA ATUAL remoto do origin/main; controller_promote deve receber exatamente aquele SHA, nunca forçar o SHA histórico caso o main tenha avançado.
- A aprovação humana é exclusivamente APPPROVED (estado GitHub APPROVED) de shopvivaliz-human no HEAD desta PR, seguido de merge pessoal deste usuário. O bot e outros agentes não podem efetuar o merge.
- Nenhuma autorização se presume do grant da #3499.
- Ler controller_status, lock/owner/dispatcher; não interromper, liberar, sobrepor ou contornar lease de durable-recovery vivo.
- Somente adquirir maintenance lease com scope controller_promote e fencing oficial quando a trava estiver legitimamente livre; promover release imutável via procedimento canônico, verificar active_sha e liberar o lease próprio.
- Separadamente, adquirir lease aplicável à instalação canônica do bridge, validar hash exato do worker, serviço ativo, heartbeat, dispatcher e continuidade E2E real vinculada. Não tocar cookies, MFA, segredos ou perfis.
- O grant do PR não constitui RBAC externo nem substitui integração do autorizador runtime. Falha de integração deve negar e registrar pendência, nunca efetuar deploy.

## Testes, risco, rollback
- scripts/merge_green_pr_via_vm.sh nega PRs que tocam security/owner-approvals/NNN.json e security/requests/ antes de chamar API merge.
- tests/test_owner_security_merge_guard.py verifica a política e o workflow pr-policy-enforcement executa a regressão.
- Risco: deploy não autorizado ou sobreposição de owner. Controles: autorização exclusiva, lease/fencing, SHA remoto exato, releases imutáveis e evidências auditáveis.
- Rollback: somente instalador/release imutável anterior e lease oficial legitimamente obtido.
