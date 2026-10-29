<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **Rotas operacionais atualizadas em 2026-10-29:** para qualquer agente (Claude, Codex, Gemini, GPT, IA híbrida, Copilot, Windsurf, browser/QA e subagentes), a fonte válida é `docs/knowledge/current-topology-and-access-2026-10-29.md` e `docs/knowledge/host-access.md` no `main`, com SHA, identidade, permissão e runbook do projeto confirmados. Web/deploy/MySQL/workers ficam na **Marina** (`marina-a1-4c24g`, OCI ESPOSA; RDC direto já comprovado); backend/browser/MCP central ficam em `always-free-arm-1787907847-26` (OCI PRINCIPAL). A VM origem `shopvivaliz-free-a1` foi excluída e nunca pode ser acessada ou recriada. O MCP central ainda a anuncia erroneamente e não executa Marina; o MCP independente Marina está inativo/não registrado. Não reutilizar aliases SSH ou receitas de quatro hosts, nem presumir RDC 2, sudo ou túnel pelo status de cloudflared. Respeitar a política central imutável, auditoria V5 com UI real, leases/fencing, segredo protegido e correção/teste/validação após cada erro. Em rotina persistente, respeitar prioridade de IA gratuita e limites de custo.
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->

# Protocolo IA-to-CLI obrigatório

Antes de qualquer alteração, leia e siga integralmente o protocolo canônico abaixo. Ele complementa, sem substituir, as regras específicas do projeto.

@../../AI-TO-CLI-PROTOCOL.md

Nenhuma alteração válida da tarefa pode ser abandonada sem merge validado na branch de destino.
