# Auditoria V5 — barreira contra encerramento em diagnostico

## Incidente observado em 2026-10-30
O agente fez verificacoes de VMs e entregou um relatorio com problemas conhecidos sem completar reparo, regressao, reauditoria e certificacao. Isso **nao** e uma Auditoria Extrema V5 concluida. Todos os agentes, incluindo ChatGPT Dev/Atendimento, Gepeto, Claude, Gemini, Codex, controladores e subagentes, devem tratar essa classe como `AUDIT_ESCAPE_DIAGNOSTIC_ONLY`.

## Regra operacional fail-closed
1. Antes de agir, ler AGENTS.md, AUDIT_POLICY.md, EXTREME_AUDIT_PROTOCOL.md, AUDIT_APTO_REMEDIATION_LOOP_V1.md, AUDIT_ABSOLUTE_GATE_V1.md e os runbooks especificos. Fixar ref, SHA, host, escopo e release.
2. Registrar inventario vivo e evidencias de runtime. Cada finding tem ID, reproducao RED, impacto, owner, causa raiz e efeito esperado.
3. Para cada finding executavel: corrigir, prevenir regressao, testar GREEN, buscar equivalentes, validar no runtime e pela UI grafica real quando houver, observar persistencia e obter revisor contraditorio independente. Reabrir qualquer finding novo.
4. Nao parar por resposta de chat, limite arbitrario, primeiro erro de ferramenta, PR aberta ou resultado do CI. Avaliar todas as rotas **independentemente autorizadas** e preservar checkpoint/lease sem duplicar work.
5. Nao contornar RBAC, MFA, protecao de branch, lease/fencing ou recusas do provedor. Para impedimento real, registrar `BLOCKED_EXTERNAL` com evidencia e proxima acao, nunca `APTO`.
6. Executar o certifier oficial com artefatos locais hash-verificados. Somente `AUDIT_VERDICT=APTO` para o SHA/release/ambiente/escopo exato autoriza declarar APTO ou CONCLUIDO.
7. Se houver finding P0-P3, dado nao reconciliado, jornada UI nao validada, divergencia de release ou manifest incompleto, o resultado e `NAO_APTO`, com trabalho aberto e proxima acao especifica.

## Teste obrigatorio dos agentes
Uma resposta que contenha apenas enumeracao de falhas e promessa de correcao, ainda que traga HTTP 200, status de systemd e screenshot, deve ser reprovada. O agente deve continuar executando o proximo passo autorizado; o revisor independente deve rejeitar certificacao sem artefatos e certifier.

## Topologia segura
Marina `marina-a1-4c24g` e producao; `always-free-arm-1787907847-26` e backend/controlador. A antiga `shopvivaliz-free-a1` foi excluida e nunca deve receber comandos. Nao usar aliases historicos.
