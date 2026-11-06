# MCP GUI write access design

## Intent

Permitir que as ferramentas `desktop_open`, `desktop_click`, `desktop_type`, `browser_navigate`, `browser_click`, `browser_click_control` e `browser_type` executem cliques e digitação sem exigir manualmente `runtime_lease_id` e `runtime_fencing_token`, removendo o bloqueio que causou `runtime_lock_required`.

## Goals and non-goals

- Goal: remover o gate de runtime lock da superfície GUI/navegador.
- Goal: preservar autenticação do MCP, allowlists de host/superfície, limites de coordenadas, validação de URL/seletor, auditoria e serialização do worker.
- Non-goal: remover autenticação, autorização de host, auditoria ou controles de operações administrativas (`service_action`, `admin_command_run`, `task_submit`, `controller_promote`).
- Anti-goal: permitir shell administrativo ou ações de conta/autenticação sem seus gates específicos.

## Constraints and approach

O gate será removido somente da chamada comum `_assert_runtime_mutation` para as ações GUI/navegador; os gates específicos de autenticação de conta e de mutações administrativas permanecem. Os schemas não exigirão campos de lease para essas ferramentas. A regressão provará que uma chamada GUI sem lease não falha com `runtime_lock_required`, enquanto uma mutação administrativa ainda exige o lock quando o handoff durável está ativo.

## Approaches considered

1. Remover o gate apenas de GUI/navegador — escolhido: resolve cliques/escrita e preserva os controles administrativos.
2. Adquirir/liberar lease automaticamente por clique — rejeitado: mantém a fricção e introduz estados de corrida desnecessários.
3. Remover todos os locks — rejeitado: enfraquece controles administrativos exigidos pelo projeto.

## Validation

Executar os testes focais do servidor MCP e do wrapper de browser, além de `git diff --check`; validar que os schemas não exigem leases para GUI e que ações administrativas continuam protegidas.
