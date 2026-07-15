# Auditoria e correcao do auto-sync — 2026-07-15

## Tarefa concluida

O auto-sync local foi alterado para sincronizar apenas historico Git seguro. O
script nao cria mais commits automaticos, nao usa `--no-verify`, nao faz merge
implicito e nao informa sucesso quando um comando Git falha.

Uma segunda rodada no mesmo dia alinhou os atalhos e a documentacao operacional
ao comportamento seguro atual e corrigiu a compatibilidade do configurador com
`powershell.exe` classico do Windows.

## Arquivos alterados

- `.gitignore`: ignora estado e mensagens de runtime do bridge MCP.
- `scripts/local-auto-sync.ps1`: implementacao canonica com lock, codigos de
  erro, fast-forward, push de commits existentes e bloqueio de divergencia.
- `scripts/auto_sync_git.ps1`: wrapper compativel com instalacoes antigas.
- `scripts/local-auto-sync-loop.ps1`: executa um ciclo por intervalo.
- `scripts/setup_auto_sync.ps1`: agenda execucao unica a cada 30 minutos e
  funciona sem exigir elevacao administrativa.
- `ATIVAR-AUTO-SYNC.bat`: passou a chamar o configurador canonico em vez de
  recriar uma tarefa inconsistente por `schtasks`.
- `SETUP_AUTO_SYNC.md`: reescrito para descrever o fluxo real, os codigos de
  saida e os logs corretos.
- `tests/test_local_auto_sync.py`: testes isolados de fast-forward, working tree
  suja e historico divergente.
- `storage/codex-bridge/messages.jsonl` e `state.json`: removidos apenas do
  indice Git; os arquivos locais de runtime permanecem preservados.

## Testes executados

- Parser PowerShell em `local-auto-sync.ps1` e `setup_auto_sync.ps1`.
- `powershell -File scripts/setup_auto_sync.ps1 -Status`.
- `python -m unittest tests.test_local_auto_sync -v`.
- Resultado: 3 testes aprovados.
- Tarefa `ShopVivaliz Auto Sync` registrada como `Ready`, sem elevacao, com
  execucao unica a cada 30 minutos em `C:\site-shopvivaliz`.

## Riscos identificados

- O checkout local estava 54 commits a frente e 1 commit atras de
  `origin/main`; o commit remoto altera cerca de 2.035 arquivos. O novo script
  bloqueia essa divergencia em vez de tentar mesclar ou publicar sozinho.
- Mudancas de outros agentes estavam presentes e continuaram aparecendo durante
  a auditoria. Elas nao foram descartadas nem incluidas nesta correcao.
- Tarefas agendadas antigas estavam desativadas e apontavam para caminhos
  anteriores a migracao.
- A tentativa de remover tres tarefas antigas do Windows Task Scheduler falhou
  com `Access denied` no contexto atual sem elevacao. Elas continuam
  desativadas, mas ainda precisam de limpeza manual administrativa.

## Proxima tarefa recomendada

Revisar a divergencia local/remota em branch ou worktree isolado antes de
integrar a correcao na `main`. O motivo e evitar perda ou publicacao acidental
das 54 alteracoes locais e do grande commit remoto ainda nao incorporado.
