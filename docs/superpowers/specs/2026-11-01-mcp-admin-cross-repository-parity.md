<!-- CENTRAL_MARINA_DUAL_REQUIREMENT_20261101 -->
> **Nova decisao do proprietario:** o MCP central tambem deve ter acesso a Marina, por rota privada propria e independentemente autorizada, alem do MCP Marina direto. A proibicao de backend como relay e valida para a **independencia do MCP Marina**, nao proibe o controlador de ter acesso explicito autorizado a Marina para suas proprias ferramentas. Nao reutilizar credenciais/SSH entre os MCPs; nao fingir que rota central read-only cobre as 29 operacoes RDC. Proposta em `docs/ops/mcp-central-marina-dual-route-2026-11-01.md`.
<!-- /CENTRAL_MARINA_DUAL_REQUIREMENT_20261101 -->

# MCP administrativo de paridade por repositorio — desenho V1

**Data:** 2026-11-01
**Situacao:** especificacao e catalogo de descoberta; nenhuma operacao de escrita instalada por este documento.

## Objetivo
O proprietario exige que **todas as rotinas administrativas anteriormente realizadas atraves dos MCPs, ferramentas e runbooks autorizados dos repositorios** voltem a ser acessiveis por ferramentas MCP. Nao basta reiniciar o Olist: e preciso cobrir observacao/diagnostico, servicos, tarefas duraveis, publicacao imutavel, recuperacao, reconciliacoes, automacoes GitHub e fluxos especificos de cada projeto, preservando controles e separacao de hosts.

## Base factual da descoberta
- Foram consultadas as arvores **main** via GitHub API, com SHA real de cada um dos 11 repositorios em `docs/ops/mcp-admin-repositories-2026-11-01.json`. Arquivos candidatos foram validados contra suas arvores.
- O primeiro lote identifica **17 candidatos**, nao o inventario completo de todos os executores historicos.
- Foi reconciliado o catalogo GitHub Actions **registrado** com os arquivos `main` de cada repo: ver `docs/ops/mcp-admin-workflow-source-audit-2026-11-01.json`. Sao **345 paths de workflows registrados como ativos sem arquivo correspondente no main** (308 em `site-shopvivaliz`, 19 em `mei-mg-email`; os demais distribuidos entre outros repositorios). Um estado `active` na API do GitHub nao prova agendamento, permissao de executar nem execucao atual: todos sao metadata `NOT_CERTIFIED`. Nao reativar, portar nem desabilitar automaticamente. Conferir referencia de branch, workflow_dispatch/cron, historico de runs e proprietario antes de cada portabilidade.
 A enumeração de GitHub workflows nao comprova que cada workflow esteja ativo, apropriado ou autorizado para disparo.
- `remote-control-mcp/SPEC.md` descreve o MCP central com ferramentas para host, processos, servicos, arquivos, logs, `admin_command_run` limitado por politica, tarefas duraveis, auditoria, controle de navegador/desktop e continuidade. O catalogo atualmente anunciado pelo conector tem 72 ferramentas, incluindo rotas apenas para backend e apoio Windows, nao para Marina.
- `marina-direct-mcp/server.py` continua um prototipo de **duas ferramentas read-only**. O modulo `operator_admin_route.py` de #3470 nao esta integrado ao servidor nem instalado na Marina.
- `docs/ops/marina-independent-secure-mcp-tunnel.md` e issue #3152 exigem um MCP **independente na Marina**, sem RDC mensal nem VM backend como relay, usando Secure MCP Tunnel oficial e credenciais distintas.
- A issue #3156 foi fechada pelo humano e nao deve ser reaberta por um agente; a #3152 permanece para o MCP independente.

## Arquitetura escolhida
**Uma experiencia administrativa ChatGPT com multiplos executores autenticados, nao um unico shell com root em todos os projetos.**

1. **Marina direto:** ChatGPT → OpenAI Secure MCP Tunnel autenticado, dedicado a Marina → 127.0.0.1:5580/mcp → service identity local → executor protegido e operações allowlisted. NENHUM transporte de comandos por backend, RustDesk ou RDC. Somente rotinas que pertencem a Marina podem ser executadas por esse servidor.
2. **Backend/Windows:** conservar e modernizar o MCP central existente, com sua propria identidade, auditoria e controles, para as tarefas que pertencem a `always-free-arm-1787907847-26`, Fred-Win e KOCEPSV. Nao usar Windows como navegador de agentes nem redirecionar a VM origem excluida.
3. **GitHub:** PRs, CI, artefatos e workflows pela API oficial com identidade/scope por organizacao, preservando branch protection. GitHub Actions nao e transporte de comandos administrativos do runtime Marina.
4. **Projetos fora da VM Marina:** `fredmourao-ai/solange-rolla-consultorio`, `solange-rolla` e outros destinos nao comprovados requerem seus proprios executores/hosts oficialmente autorizados. Nao herdar credenciais de ShopVivaliz nem assumir que qualquer repositorio reside em Marina.
5. **Roteador logico / catalogo:** cada rotina tem `target_repository`, `operation`, `host`, `kind`, `capability`, identidade de execucao, contrato de argumentos, impacto, projeto, origem versionada e prova E2E. Resolver destino por identidade local ou mecanismo oficial, nunca por hostname/IP arbitrario fornecido pelo chamador.

## Autorizacao
- `shopvivaliz-human` e exclusivamente humano e nunca recebe login, device flow, token ou sessao de automacao.
- Qualquer **concessao nova de seguranca** e concedida por **PR separado de codigo**, criado por agente diferente, revisado, aprovado e mergeado pessoalmente pelo humano. O grant imutavel inclui repositorio de destino, operacao exata, capacidade, recurso, principal, expiração e vinculo a SHA/review. A PR #3470 e a PR de endurecimento subsequente sao PRs **de codigo**, nao grants.
- O host valida as permissoes efetivas de OS/OCI/Platform; a autorizacao GitHub nunca substitui MFA, RBAC externo ou lease/lock real. Sem permissao, DENIED.
- Tarefas administrativas de mutacao exigem idempotencia, lease/fencing e CAS apropriados, auditoria redigida, rollback testado, limites e evidencias de runtime. Financeiro, compras, emissao fiscal, reembolsos e SAFE-T mantem seus gates de negocio; `CREDIT_CONFIRMED` permanece terminal para SAFE-T.
- Nada de shell/root universal sem contrato independente e autorizacao apropriada; segredo/sessao nao vira parametro de MCP nem registro.

## Plano de cobertura
| Projeto/repo | Familia de rotinas descoberta | Local previsto (a validar) | Estado atual |
|---|---|---|---|
| Vivaliz-site/site-shopvivaliz | release imutavel, diagnostico, controller e jobs | Marina e backend separados | descoberta |
| Vivaliz-site/amazon-returns-safet | Olist/SAFE-T, reconciliacao, UI, recuperacao | Marina + navegador backend | descoberta |
| Vivaliz-site/mercadolivre-returns-recovery | retornos ML, reconciliacoes, workers | Marina | descoberta |
| Vivaliz-site/ml-pricing-api | deploy, trabalhador e financeiro | Marina | descoberta |
| Vivaliz-site/shopvivaliz-m365 | administracao Microsoft 365 | backend + provedor externo | descoberta |
| Vivaliz-site/buscador | MCP Buscador, auditoria UI | host a confirmar | descoberta |
| Vivaliz-site/-shopvivaliz-pipeline | CI/pipeline controlado | GitHub | descoberta |
| fredmourao-ai/mei-mg-email | fila, NDR, sincronizacao, backup | backend + provedor externo | descoberta |
| fredmourao-ai/solange-rolla-consultorio | staging, DB, backup e preview | ambiente proprio a validar | descoberta |
| fredmourao-ai/solange-rolla | PR/CI/governanca | GitHub, runtime a validar | descoberta |
| Vivaliz-site/demo-repository | sem operacao administrativa encontrada | apenas governanca | nenhuma identificada |

A coluna **descoberta** nao implica serviço, identidade, listener ou ferramenta MCP disponivel. Os 17 nomes iniciais sao amostras vinculadas a arquivo/commit, NAO garantias de paridade.

## Abordagens descartadas
- Acrescentar Marina como alias do host antigo no MCP central: fere identidade, topologia, exigencia de independência e recusa de seguranca.
- Transformar o MCP read-only em daemon root/SQL/shell genericamente: cria privilegio nao auditavel e excede o consentimento.
- Usar GitHub Actions para rodar todo comando administrativo da Marina: substitui indevidamente transporte de runtime, lease e identidade de host.

## Criterios de aceite finais
1. Inventario extensivel cobrindo TODAS as rotinas administrativas ativas, confrontado com a ultima versao dos runbooks, scripts, workflows e controles historicos, classificando legados/obsoletos. Cada repositorio tem matriz de paridade ferramenta antiga → nova ferramenta, argumentos, identidade, host, risco, status e ultimo E2E.
2. Servicos e tunnel Marina realmente ativos com configuracao protegida, 401/403 negativos, `tools/list` e tool real via ChatGPT workspace/Platform oficiais, sem RDC nem backend; reinicio e reconexao comprovados em runtime.
3. Executor local Marina provido por operador legitimamente privilegiado, com capacidades estritamente mapeadas e atomicidade de lease/fence/idempotencia; sem tocar `current` ou credenciais.
4. Cada familia e cada repositorio sao certificados independentemente, com testes negativos de cross-repo, host, escopo, repeticao, parametro malformado, permissao externa ausente, rollback e runtime real.
5. Na ausência de qualquer gate, declarar `NAO_APTO` apenas para aquele componente e prosseguir sem representar o restante como concluido; a migracao como um todo exige cobertura comprovada.

## Etapas de implementacao
Ver `docs/superpowers/plans/2026-11-01-mcp-admin-cross-repository-parity.md`. O inventario inicial e o contrato do grant sao **preparatorios**; nao foi criada nova ferramenta de mutacao no servidor nem feito deploy por este plano.
