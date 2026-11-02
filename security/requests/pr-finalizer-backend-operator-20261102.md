# Autorizacao formal P2 — rota de execucao CI do finalizador de PRs

Data: 2026-11-02. Escopo: somente o repositorio
`Vivaliz-site/site-shopvivaliz` e seus dois workflows PR Completion Enforcer e
PR Conflict Auto-Healer. Esta e uma mudanca de trust boundary do runner, nao
uma autorizacao global para administracao da VM, git ou marketplace.

## Problema comprovado
- O runner de producao Marina e valido, mas o usuario `ubuntu` ali nao possui
  sessao independente do GitHub CLI; o reparo falha com
  `independent_github_auth_unavailable`.
- O backend ja possui o runner `shopvivaliz-backend-browser` online,
  executado como `ubuntu` com GitHub CLI autenticado especificamente como
  `fredmourao-ai`. Ollama gratuito ja existe no backend.
- Nao transportar ou expor credenciais entre hosts nem criar runner duplicado.

## Efeito exato solicitado
- Usar o runner backend existente apenas para o helper de sincronizacao,
  tratamento de conflitos e merge tecnicos que ja eram autorizados.
- Executar exclusivamente codigo confiavel da branch `main`, confirmar host,
  identidade e permissao; nunca executar codigo do PR como script privilegiado.
- Remover `GH_TOKEN` e `GITHUB_TOKEN` de qualquer escrita Git, mantendo
  a identidade tecnica independente e comprovada do host.
- Resolver conflitos reais com Gemini privado se legitimamente provisionado
  no host, ou Ollama local gratuito; recusar conflitos em caminhos protegidos.
- Recusar qualquer alteracao de HEAD em PR de autorizacao formal do proprietario,
  inclusive antes de revisao; nao auto-aprovar nem auto-mergear essas PRs.
- Aplicar lock por PR e conferir estado, SHA, CAS, gates, branch protection,
  reviews e progresso em checks oficiais. Nao mudar flags comerciais, ambiente
  de producao, banco, workers ou sistema de autorizacao.

## Risco, verificacao e rollback
- Risco P2: runner backend alcanca credenciais GitHub tecnicas ja provisionadas.
  Mitigacoes: gatilhos restritos aos workflows e codigo trusted/main; principal
  fixo, readback, lock, protecoes, caminho de grant excluido e fail-closed.
- Testes direcionados: politica estatica, compilacao Python, bash -n,
  YAML, diff --check, testes de concorrencia/permissao e testes negativos
  da revisao humana no HEAD. Nenhum merge sem required checks verdes.
- Rollback por novo PR protegido revertendo os dois workflows e helper para
  ultima versao validada; sem copiar segredo nem bypass, sabendo que runner
  Marina ainda carece de GitHub CLI autorizado.
- A aprovacao exigida neste proprio PR deve ser `APPROVED` nativa no HEAD
  exato pela conta exclusiva `shopvivaliz-human`. Somente esse proprietario
  podera habilitar auto-merge protegido ou efetuar merge. Agentes nao fazem
  essa operacao em seu nome.
