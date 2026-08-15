# Política obrigatória de Pull Requests para agentes

Esta política vale para **todos os agentes e automações** que atuam neste repositório (Claude, Codex, GPT, Gemini, Copilot, Roo e equivalentes).

## Regra principal

Nenhum agente pode encerrar uma rodada de trabalho deixando Pull Request pendente ou abandonada.

O fluxo obrigatório para qualquer alteração versionada é:

1. concluir a alteração;
2. validar o que foi alterado;
3. criar commit;
4. abrir ou atualizar a Pull Request;
5. aguardar/obter os checks e validações exigidos;
6. fazer merge quando as proteções permitirem;
7. confirmar que não ficou PR aberta da própria rodada.

Se houver impedimento real para merge, o agente deve registrar o trabalho como **INCONCLUSIVO**, explicar objetivamente o bloqueio e encerrar/limpar a PR quando ela não puder mais avançar. Não é permitido usar draft PR como arquivo permanente de log, memória, reconfirmação periódica ou diagnóstico repetido.

## Rotinas autônomas e documentação

Rotinas periódicas não devem abrir uma nova PR apenas para registrar que nada mudou. Quando não houver mudança funcional ou documental necessária, a rotina deve apenas produzir evidência/log fora de uma PR. Se uma atualização documental for realmente necessária, ela deve seguir o fluxo completo até merge na mesma rodada.

## Diagnósticos de secrets e ambiente

Um agente não pode concluir que uma credencial inexiste no projeto apenas porque ela não está exposta no sandbox da sessão. Antes de registrar ausência de `SHOPEE_*`, `TINY_*`, `OLIST_*` ou outros secrets operacionais, deve distinguir explicitamente:

- secrets disponíveis ao sandbox/agente;
- GitHub Actions secrets/environment secrets;
- secrets materializados no runtime real da VM (`shared/.env` / `runtime-secrets.php`);
- credencial existente porém inválida/expirada.

Ausência em um desses contextos não prova ausência nos demais.

## Encerramento obrigatório

Ao finalizar uma tarefa, o agente deve verificar PRs abertas relacionadas à própria rodada. Se houver PR antiga, duplicada, obsoleta ou baseada em diagnóstico superado, deve encerrá-la com comentário explicativo. O repositório não deve acumular PRs pendentes de agentes sem um bloqueio ativo e documentado.

Esta política reforça a regra já existente em `AGENTS.md`: **Commit → PR → validação → Merge**, sem finalizar rodadas deixando alterações apenas locais ou PRs pendentes.
