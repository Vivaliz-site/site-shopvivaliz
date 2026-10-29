# Auditoria de consistência documental dos agentes — 2026-10-29

**Escopo:** repositório `Vivaliz-site/site-shopvivaliz`; contrato vigente de hosts, MCP, SSH/OCI/RDC, governança, continuidade, preço e descomissionamento. **Esta auditoria é de documentação e código de roteamento**, não substitui a Auditoria Extrema V5 de UI/produção.

**Fonte de verdade:** `main` + `docs/knowledge/current-topology-and-access-2026-10-29.md`, `docs/knowledge/host-access.md`, `docs/knowledge/agent-rules.md`, `AGENTS.md`, documentação específica e evidência do runtime. Padrões imutáveis protegidos por manifesto não são alterados sem seu processo de governança.

## Inventário e metodologia

- Árvore Git principal inspecionada: **6.039 entradas**, **5.373 blobs**. Arquivos de texto documental identificados: **710 `.md` + 104 `.txt` = 814** (inventário de paths; inclui relatórios antigos, planos, checkpoints e instruções de agentes).
- Varredura por `git grep` em documentos rastreados e leitura direcionada dos runbooks e entrypoints com impacto operacional. **56** arquivos documentais retornaram referência a `shopvivaliz-free-a1`; **18** retornaram `NO_DECOMMISSION`/quatro hosts; **4** citaram `NoNewPrivileges=yes/no`; **129** citaram MCP/Desktop Commander/Tailscale; **35** citaram auditoria extrema/retomada; **17** citaram termos FBA/SAFE-T de janela; o documento sobre origem de preço encontrado foi `docs/knowledge/pricing-integrity.md`.
- Os números acima são ocorrências por **arquivo**, não falhas. As assinaturas detectam riscos conhecidos; **não equivalem a revisão semântica manual de cada linha dos 814 arquivos**. Documentos históricos mantêm fatos e evidências, mas não autorizam execução atual.
- Comparação de diretivas: agente/CLI, atendimento, Claude, Codex, Gemini, Copilot, Cursor, Windsurf, híbridos e MCP. Preferir evidência recente ao título ou à data de uma nota histórica.

## Resultados e tratamento

| Prioridade | Achado | Resultado documental | Gate ainda aberto |
|---|---|---|---|
| P0 | `shopvivaliz-free-a1` foi excluída segundo proprietário, mas `hosts_list` no MCP ainda retorna essa origem como produção e não inclui Marina | Banimento explícito em bootstraps, specs e índice canônico; sem aliases de redirecionamento | Corrigir `remote-control-mcp/server.py`, testes e deploy com host retire fail-closed, sem introduzir SSH/privilegiado novo |
| P0 | Scripts/workflows de produção, diagnóstico, SAFE-T, runner rescue e sentinelas ainda contêm destino remoto antigo | Documentação diferencia históricos de caminhos executáveis | Auditar/desabilitar/migrar por workflow com owner, testes e gate; não executar contra origem |
| P0 | Controlador 24x7 ativo com `degraded=true`, checkpoints/sessões não vinculados e release anterior ao `main` | Estado documentado como degradado, sem falsos `APTO` | Reconciliar pelo mecanismo canônico, com lease e validação, em tarefa/runtime próprio |
| P1 | Runbooks anteriores à exclusão dizem `NO_DECOMMISSION`, preservar/operar a origem e parar timer nela | Alertas de supersessão colocados em checkpoints, auditorias, monitor e runbooks afetados; preservar conteúdo como histórico | Revalidar backups, dados, schedulers e ownership na Marina; exclusão não prova cutover completo |
| P1 | Acesso à Marina descrito genericamente como via MCP central ou via sudo sem prova | Rota efetiva por destino: RDC direto confirmado; SSH privado somente autenticado; MCP da Marina em staging/read-only, tunnel inativo | MCP independente exige teste de app ChatGPT e RBAC; sudo/maintenance somente via canal oficialmente autorizado |
| P1 | GA4 tinha etapa ativa apontando `GA4_SECRET` à produção antiga | Etapa reclassificada como histórica e ambiente de produção atualizado para Marina | Provar metadados do secret no destino antes de alterar; não copiar/exportar token nem usar origem |
| P1 | Monitor de IA gratuita, rotina antiabandono e contrato de carrinho ainda falavam em execução na origem | Alertas de rotina histórica e proibição de reativação da origem; nenhuma instalação automática executada | Inventariar timers reais da Marina/backend e impedir duplicidade com testes de runtime |
| P1 | Proteção de hashes imutáveis rejeita edições casuais em `AGENTS.override.md` e `REGRAS-AGENTES-CENTRALIZADAS.md` | Arquivos protegidos preservados exatamente como em `main`; políticas complementares distribuídas em pontos de entrada permitidos | Qualquer edição futura nesses blobs deve usar o processo oficial de mudança/assinatura, nunca ajustar hashes para burlar gate |
| P1 | Preflight dos agentes não continha o inventário pós-migração na lista efetiva de arquivos | Incluído em `scripts/agent_docs_gate.py`, com regressão para recibo obsoleto e cheque dos principais entrypoints | Validar testes e checks do PR; recibos anteriores devem ser relidos/renovados |
| P2 | Políticas comerciais e de custo em notas históricas poderiam parecer atuais | Política atual prevalente: site usa a tabela **Amazon (ID 991)** do ERP Olist/Tiny; não usar PDV, preço do anúncio externo ou preço base como fallback. Rotinas persistentes respeitam prioridade de IA gratuita | Verificar paridade real em catálogo/carrinho/checkout e política de custos em execução separada |

## Fontes concretas dos riscos operacionais ainda não corrigidos

- MCP central e allowlist obsoleta: `remote-control-mcp/server.py`, `scripts/setup-remote-control-access.sh`, `remote-control-mcp/SPEC.md`, `remote-control-mcp/CHECKPOINT.md`.
- Sentinelas e SSH da origem: `scripts/desktop-commander-four-host-sentinel.sh`, `scripts/oci-private-access-bootstrap.sh`, `docs/DESKTOP-COMMANDER-24H.md`, `docs/ROUTINE-ABANDONMENT-MONITOR.md`.
- Workflows com host excluído: `.github/workflows/production-functional-audit.yml`, `.github/workflows/amazon-returns-safet-recovery.yml`, `.github/workflows/mlrr-production-ops-bridge.yml`, `.github/workflows/production-runner-rescue.yml`, `.github/workflows/recover-shopvivaliz-runner.yml`, `.github/workflows/desktop-commander-24h-health.yml`. Estes paths precisam de inspeção por rotina; a presença textual não prova agendamento ativo.
- Deltas e rastreabilidade da migração: `docs/ops/oci-marina-migration-v5-20261017.md`, `docs/ops/marina-abandoned-cart-single-writer.md`, `docs/quality/AUDIT_STATUS.md`.
- MCP independente ainda não ativado: `marina-direct-mcp/README.md`, `docs/ops/marina-independent-secure-mcp-tunnel.md`.

## Política de precedência e prevenção

1. Antes de qualquer mutação, ler o `main` atual e executar o preflight oficial `scripts/agent-docs-gate.py read -> acknowledge -> verify`; o documento de topologia agora participa do hash. Documento datado não substitui identidade, capacidade de execução e estado de runtime.
2. Apresentar somente hosts ainda existentes e seus transportes autorizados; **nunca** usar `shopvivaliz-free-a1` nem recriar o alvo. Não trocar IP, tenancy, SSH ou identidade de agente por simples string replacement.
3. Ler `AGENTS.md`, políticas canônicas protegidas e runbooks atuais; o inventário posterior prevalece apenas para **roteamento de hosts**, não anula outras regras de segurança, MFA, RBAC, CI, backups, ownership e leases/fencing.
4. Para cada achado de erro corrigível: reproduzir -> causa raiz -> corrigir -> prevenir -> GREEN/regressão -> runtime/UI/E2E conforme aplicável. Históricos com resultados `APTO` por escopo específico não certificam a migração inteira.
5. Rodar `python3 scripts/check-agent-docs-enforcement.py` e `python3 -m unittest tests/test_agent_docs_gate.py` nos gates da branch e exigir CI antes do merge. O teste verifica presença da topologia em todos os entrypoints de agente atualizados.

## Evidência de acesso verificada (snapshot, não garantia futura)

Em 2026-10-29, RDC direto listou Marina e laptop online; `marina-a1-4c24g` respondeu como `ubuntu`; Apache, MySQL, runner de deploy e Cloudflare admin responderam ativos; MCP independente e tunnel-client da Marina responderam inativos. A revisão de produção ativa naquele instante era `releases/20261029-101846-79307562`. O MCP central alcançava o backend, mas ainda anunciava a origem. A continuidade 24x7 estava degradada. A mensagem do proprietário confirmou a exclusão da origem; não foi efetuada uma segunda consulta OCI de lifecycle nesta auditoria.

**Limite de conclusão:** documentação e contratos podem ficar consistentes sem que workflows ou MCPs de runtime tenham sido alterados. Não anunciar `APTO` ou `MIGRACAO_CONCLUIDA` apenas por esta PR. O que continuar falhando no runtime deve permanecer pendente, com responsável, evidência, prioridade e passo exato para retomar.
