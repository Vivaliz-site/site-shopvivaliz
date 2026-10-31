---
trigger: always_on
---
<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Estado de runtime posterior, prevalente (2026-10-29):** o MCP central do backend agora anuncia **somente backend, Fred-Win e KOCEPSV**; a origem `shopvivaliz-free-a1` foi removida da allowlist (PRs #3391/#3392) e nunca e alvo. Marina permanece fora do MCP central, mas RDC direto e SSH privado foram comprovados; o tunel SSH do MEI para Marina esta ativo (HTTP 200), separado do MCP independente Marina que continua nao instalado. O PR #3395 bloqueou 20 jobs antigos sem migrar automaticamente seus objetivos. Controlador 24x7 ainda precisa de lease valido e recuperacao de checkpoints; CI `mei-ci-a1` segue sem registro. Declaracoes de "MCP ainda inclui origem" abaixo sao apenas historicas. Referencia: `docs/knowledge/current-topology-and-access-2026-10-29.md`.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->


<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **Rotas operacionais atualizadas em 2026-10-29:** para qualquer agente (Claude, Codex, Gemini, GPT, IA híbrida, Copilot, Windsurf, browser/QA e subagentes), a fonte válida é `docs/knowledge/current-topology-and-access-2026-10-29.md` e `docs/knowledge/host-access.md` no `main`, com SHA, identidade, permissão e runbook do projeto confirmados. Web/deploy/MySQL/workers ficam na **Marina** (`marina-a1-4c24g`, OCI ESPOSA; RDC direto já comprovado); backend/browser/MCP central ficam em `always-free-arm-1787907847-26` (OCI PRINCIPAL). A VM origem `shopvivaliz-free-a1` foi excluída e nunca pode ser acessada ou recriada. O MCP central ainda a anuncia erroneamente e não executa Marina; o MCP independente Marina está inativo/não registrado. Não reutilizar aliases SSH ou receitas de quatro hosts, nem presumir RDC 2, sudo ou túnel pelo status de cloudflared. Respeitar a política central imutável, auditoria V5 com UI real, leases/fencing, segredo protegido e correção/teste/validação após cada erro. Em rotina persistente, respeitar prioridade de IA gratuita e limites de custo.
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->



# Protocolo IA-to-CLI obrigatório

Antes de qualquer alteração, leia integralmente `AI-TO-CLI-PROTOCOL.md` no root do repositório e siga-o. Não edite antes dessa leitura. Esta regra complementa as regras específicas do projeto e não pode enfraquecê-las.

Nenhuma alteração válida da tarefa pode ser abandonada sem merge validado na branch de destino.
