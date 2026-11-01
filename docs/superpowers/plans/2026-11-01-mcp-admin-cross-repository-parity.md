<!-- CENTRAL_MARINA_DUAL_REQUIREMENT_20261101 -->
> **Atualizacao de escopo 2026-11-01:** alem do MCP independente da Marina (sem RDC/backend), o MCP central deve alcancar a Marina por rota privada explicitamente autorizada. A PR #3474 prepara somente health/status read-only, ainda desligados por padrao; implementar grant owner-only separado e E2E antes de habilitar, depois expandir controles por operacao. O plano antigo de independencia nao elimina esta segunda via de acesso.
<!-- /CENTRAL_MARINA_DUAL_REQUIREMENT_20261101 -->

# MCP administrativo em todos os repositorios — plano de implementacao

> **Para agentes:** executar com Superpowers TDD, testes negativos e verificaçao de runtime por etapa. Somente `shopvivaliz-human` faz merge de PRs de concessao/seguranca.

**Objetivo:** recuperar paridade das rotinas administrativas de cada repositorio atraves de MCPs legitimamente autenticados, sem dependência do backend ou RDC para Marina.

**Arquitetura:** fachada logica ChatGPT com MCP Marina independente, MCP backend e executores externos independentes. Registro versionado por repositorio + operacao + destino, concessoes por PR humano e permissao real de cada host.

**Tecnologia:** Python stdlib / MCP Streamable HTTP, OpenAI Secure MCP Tunnel oficial, systemd imutavel, GitHub API para source/CI somente, unittest e testes E2E.

**Especificacao:** `docs/superpowers/specs/2026-11-01-mcp-admin-cross-repository-parity.md`.

## Restrições universais
- A prova de uma PR de codigo, como #3470, nao substitui grant administrativo exato `security/owner-approvals/<PR>.json`.
- A VM `shopvivaliz-free-a1` foi excluida; nao acessa-la, ressuscita-la, criar alias ou redirecionar para Marina.
- Browser de agentes somente na VM backend; RDC/SSH publico nao substituem o novo MCP Marina.
- `NoNewPrivileges`, RBAC externo, MFA, rulesets, leases e recusas continuam validos.
- Olinst/SAFE-T exige `CREDIT_CONFIRMED` quando aplicavel, sem acao financeira antecipada.
- Cada tarefa = checklist/checkpoint duravel com evidencias verdadeiras, e nenhuma marcacao de concluido sem teste E2E.

## Fase 0: inventario e modelo de autorizacao
- [x] Consultar 11 arvores GitHub `main`, documentar commits, workflows e 17 candidatos com fonte real (inicial, nao exaustivo).
- [x] Reconciliar metadados de workflows registrados com arquivos main; 345 paths ativos nao encontrados no main, todos nao certificados e sem habilitacao.
- [x] Adicionar validador que rejeita alias antigo, escopo selvagem, ativacao nao certificada e parametros malformados.
- [x] Vincular grant Marina por repo de destino e operacao exata com TDD RED/GREEN, sem conceder permissao ou fazer deploy.
- [ ] Expandir catalogo a TODOS os workflows ativos/operacoes historicas, cruzar 72 ferramentas MCP anunciadas com scripts/runbooks/unidades por projeto, deduplicar e classificar funcao/comportamento.
- [ ] Investigar os 345 registros ativos sem fonte no main quanto a branch, trigger, ultima execucao, owner e desativacao oficial; nunca migrar ou disparar automaticamente.
- [ ] Certificar inventario com historico real de execucao, owners e dry-runs por familia. A quantidade 17 nao equivale a paridade.

## Fase 1: provisionamento oficial da rota Marina
- [ ] Pela OpenAI Platform org correta, validar `Tunnels Read+Manage`, `Read+Use` e vinculacao ao workspace corporativo.
- [ ] Na Marina, sob operador autorizado (nao RDC negado), provisionar identidade/segredos dedicados `0600`, client oficial atualizado e releases root-owned imutaveis.
- [ ] Habilitar somente ferramentas read-only (health/status) inicialmente; testar 401/403, `tools/list`, `marina_host_health` via ChatGPT real; provar indepêndencia backend/RDC.
- [ ] Executar preflight e comprovar reinicio/reconexao/rollback sem interferir em Apache, DB, estoque, Olist e SAFE-T.

## Fase 2: operador e gates para as rotinas administrativas
- [ ] Implementar pacote local protegido com interfaces tipadas `operation(repo, host, arguments)` em vez de aceitar comandos arbitrarios.
- [ ] Validar grant PR por `shopvivaliz-human` + repo/operation/capability/sha/expiry no instante de cada mutacao.
- [ ] Implementar TTL, lease/fencing/CAS/idempotencia, audit append-only redigido, saida limitada, supervisao e rollback.
- [ ] Expor `tools/list` de operacoes efetivamente instaladas; rotinas nao provisionadas continuam invisiveis como executaveis.
- [ ] Testar abusos (grant de outro repo, outra operacao, expired, replay, root/wildcard, secrets, buffer, concorrencia) antes de cada enable.

## Fase 3: paridade dos onze repositorios por grupos
- [ ] Marina: `site-shopvivaliz` release imutavel, workers/health/logs, `amazon-returns-safet` com credit reconciliation, `mercadolivre-returns-recovery` e `ml-pricing-api` sem double-writers.
- [ ] Backend: continuar MEI/M365/Browser/Windows pelo MCP proprio existente, preservando seus executores; diferenciar URI de controle e autenticacao do MCP Marina.
- [ ] GitHub: PRs, CI, workflow_dispatch e artefatos pela identidade do agente autorizada e protecoes GitHub; NAO tornar GitHub transporte de shell da Marina.
- [ ] Buscador/solange-rolla/consultorio/pipeline/demo: certificar quais rotinas sao administrativas e quais sao somente desenvolvimento; localizar ambiente real e integrar apenas no controlador proprio autorizado.
- [ ] Para cada repo, mapear TODOS os caminhos operacionais historicos, executar smoke/read, testes controlados de mutacao no ambiente correto, teste UI de negocio e rollback.

## Fase 4: promocao, E2E e fechamento
- [ ] Promover somente merges validados e releases imutaveis. PRs de seguranca ficam Draft ate review e merge exclusivamente humanos.
- [ ] Rodar teste de paridade ferramenta antiga → MCP novo por repo, confirmar readback de runtime e evidência audit ID/status/sha.
- [ ] Testar Marina conectada ao ChatGPT sem backend e RDC, reboot, rotacao autorizada e recuperacao idempotente.
- [ ] Registrar status por rotina (APTO/NAO_APTO/BLOCKED_EXTERNAL) com fatos frescos; nenhuma conclusao global antes da ultima rotina criticar passar.
