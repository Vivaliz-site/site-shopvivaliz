# Plano de correção da classificação por efeito — PR #3850

> Execução técnica com testes RED → GREEN, regressão ampliada e integração somente pelos gates oficiais.

**Objetivo:** exigir revisão dupla apenas quando uma alteração realmente cria ou agrava restrição, evitando revisão humana desnecessária em mudanças técnicas.

**Arquitetura:** preservar as funções públicas `is_lock_insertion` e `requires_dual_approval`, comparar permissões e quantidade de revisões antes/depois nos hunks e não desconsiderar categoricamente os arquivos de testes.

**Base:** `dc43260a8603990f029cd06976eab6329e8fb716`, branch existente da PR #3850. Sem edição de `main`, sem deploy e sem alteração de produção.

### Etapa 1 — testes de reprodução
- [x] Reproduzir falso bloqueio quando `permissions` aumenta privilégios.
- [x] Reproduzir falso bloqueio quando `required_approving_review_count` diminui.
- [x] Reproduzir ausência de classificação de novo gate em arquivo de testes.
- [x] Reproduzir falsos bloqueios de contextos inalterados e exemplo Markdown.
- [x] Confirmar falhas RED intencionais antes das correções.

### Etapa 2 — correção mínima
- [x] Comparar níveis de permissões escalares e por escopo.
- [x] Comparar aumento efetivo da quantidade de revisores exigidos.
- [x] Retirar a isenção integral de arquivos de testes para novos gates.
- [x] Excluir exemplos Markdown da interpretação como configuração executável.
- [x] Preservar bloqueio por evidência ausente e revisão vinculada ao HEAD.

### Etapa 3 — validação e integração
- [x] Testes focais GREEN: 23/23.
- [x] Regressão Python por grupos: 79 + 94 + 27 testes (antes da última correção; executar novamente no HEAD final).
- [x] `python3 -m py_compile` e `git diff --check` na árvore local.
- [ ] Reexecutar regressão, analisar diff completo, registrar evidência.
- [ ] Commit/push exclusivamente na branch existente da PR #3850.
- [ ] Validar checks oficiais e as revisões `APPROVED` das duas identidades no HEAD final.
- [ ] Mesclar apenas mediante gates reais e aprovação humana; confirmar `main` após merge.

**Restrições:** não converter uma simples edição de arquivo protegido em exigência de revisão; não criar gates universais; não simular aprovações ou resultados de produção.
