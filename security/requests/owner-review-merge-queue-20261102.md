# Pedido P2: aprovação única, fila de merge segura

**Data:** 2026-11-02. **Repositório:** Vivaliz-site/site-shopvivaliz.
A aprovação desta PR deve vir de shopvivaliz-human via GitHub APPROVED no
HEAD exato. A ativação só pode ocorrer depois que esta PR for mesclada
pela própria conta humana; código em branch não concede autorização.

## Problema comprovado

O ruleset principal 24523478 exige status checks estritos
(validation-gate e Audit repository automation), branch atualizada
e descarta reviews ao modificar o HEAD. Quando main avança após a
aprovação humana, uma PR atrás da base fica bloqueada; atualizar a
branch revoga o review, gerando aprovações repetidas.
O finalizador anterior usa runner Marina sem credencial técnica GitHub
independente. A PR #3543 corrige a rota de runner separadamente.

## Escopo da autorização solicitada

1. Adicionar SOMENTE regra merge_queue ao ruleset canônico 24523478.
   ALLGREEN, SQUASH, um item por grupo, uma build concorrente e timeout
   60 min. Preservar todos os checks, strict=true, revisão e proibições
   de force-push, non-fast-forward e exclusão. Nenhum bypass.
2. Após REVIEW APPROVED EXATO de shopvivaliz-human, permitir que
   fredmourao-ai apenas solicite enqueuePullRequest pela API oficial.
   O GitHub valida o merge_group contra a main atual e integra como bot,
   sem reescrever o SHA aprovado e sem exigir Enable auto-merge ou Merge.
   O operador não pode aprovar, configurar bypass ou fazer merge direto.
3. Grants protegidos só reconhecem github-merge-queue[bot] se o
   autorizador validar timeline GraphQL oficial AddToMergeQueue posterior
   ao review humano, ator técnico legítimo e nenhuma remoção posterior.
   Permanecem SHA, arquivo imutável, escopo, TTL, RBAC e MFA.
4. A ativação só ocorre após ESTE PR ter sido aprovado na revisão nativa,
   com o HEAD e diff do pedido exatos e merged_by=shopvivaliz-human.
   A conta técnica backend já existente é usada, sem copiar credenciais.

## Correção efetiva do gate de revisão no mesmo diff

Este diff também conecta o verificador owner-only do main ao required
Mandatory Validation Gate, que antes permitia sucesso de PR P2 sem
APPROVED. A verificação usa o target base SHA confiável, token oficial
somente leitura, SHA/PR exatos e falha fechada. Mantém os checks existentes
e a proteção comercial independente de preços/estoque.

A integração aqui substitui a solução equivalente da PR #3551. Depois
de confirmar o código e os testes nesta PR, fechar a #3551 como duplicada
sem fazer merge nela. **A aprovação pessoal necessária é uma só: esta PR
#3552 no HEAD final, pela conta shopvivaliz-human.** O ativador
exige em seu diff o workflow mandatory-validation-gate.yml, presente aqui.

## Risco, rollback e aceitação

Mudança P2 nas regras de integração e na fronteira de autorização.
Testar unitariamente cenários negativos de agente/review falso/HEAD
alterado e os dois checks oficiais em merge_group. Depois da ativação,
validar E2E: owner aprova uma vez, agente enfileira, GitHub testa e
mescla; a autorização usa a timeline como prova. Se falhar, suspender
enfileiramento, manter status checks e regras de segurança, corrigir
causa e propor rollback autorizado da regra merge_queue sem bypass.
Nunca habilitar globalmente o agente como aprovador humano.
