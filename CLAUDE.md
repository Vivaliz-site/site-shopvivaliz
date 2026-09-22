<!-- GEPETO_EVERY_TASK_V1 -->
## Gepeto obrigatorio em toda tarefa
Acione o plugin Gepeto junto com Superpowers em toda tarefa e retomada, sem nova mencao do usuario. Leia e cumpra `GEPETO-POLICY.md`. Se o runtime nao expuser o plugin, registre `GEPETO_UNAVAILABLE`, informe a limitacao e continue o trabalho autorizado sem simular participacao. Aplicar um plugin nao comprova delegacao nem revisao independente.
<!-- /GEPETO_EVERY_TASK_V1 -->

<!-- CLAUDE_READ_ROOT_AGENTS_FIRST_V1 -->
> **BOOTSTRAP OBRIGATÓRIO DO CLAUDE:** antes de analisar, planejar, editar, executar comandos, usar navegador, abrir PR, fazer merge, deploy ou validar qualquer tarefa, leia integralmente o `AGENTS.md` da raiz deste repositório e siga suas regras. Em particular, leia `docs/AGENT-REMOTE-ACCESS.md` antes de qualquer operação em host/VM/runtime/browser.

@docs/knowledge/claude-vm-bootstrap.md
@docs/AGENTS.md
<!-- audit-refs: AUDIT_POLICY.md docs/quality/EXTREME_AUDIT_PROTOCOL.md docs/quality/AUDIT_RUNTIME_PARITY_V1.md docs/quality/AUDIT_UNIVERSAL_COVERAGE_V1.md docs/quality/AUDIT_SELF_TEST_V1.md docs/quality/ARCHITECTURE_DEPLOY_AUDIT_V1.md -->
<!-- gate: FINAL_RESPONSE_DEPLOY_GATE_V1 — resposta final só após validação pós-deploy completa -->


<!-- AUDIT_ABSOLUTE_V5_ENTRYPOINT -->
Leia primeiro `AGENTS.md`. Para auditoria/aptidão, cumpra `AUDIT_ABSOLUTE_GATE_V1.md`, `AUDIT_BROWSER_E2E_REAL_V1.md`, `AUDIT_AUTH_CREDENTIAL_DISCOVERY_V1.md`, `AUDIT_PROJECT_REQUIREMENTS_V1.md` e o manifesto local de requisitos. Somente o certifier pode autorizar APTO.


<!-- AUDIT_MERGE_ENFORCEMENT_V1 -->
Leia `docs/quality/AUDIT_MERGE_ENFORCEMENT_V1.md`; preserve o governance bridge e o Absolute Audit Main Guard. Somente o certifier absoluto autoriza APTO.
