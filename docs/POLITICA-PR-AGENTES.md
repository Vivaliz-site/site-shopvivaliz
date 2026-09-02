# Política obrigatória de Pull Requests para agentes

Esta política vale para **todos os agentes e automações** que atuam neste repositório (Claude, Codex, GPT, Gemini, Copilot, Roo e equivalentes).

## Regra principal

Nenhum agente pode encerrar uma rodada de trabalho deixando Pull Request pendente ou abandonada.

**Commit é somente checkpoint intermediário.** Também é proibido encerrar a rodada apenas porque existe um commit local, uma branch remota ou uma PR verde. O agente deve continuar até o merge e a verificação pós-merge, salvo bloqueio externo real e documentado.

O fluxo obrigatório para qualquer alteração versionada é:

1. concluir a alteração;
2. validar o que foi alterado;
3. criar commit;
4. abrir ou atualizar a Pull Request;
5. aguardar/obter os checks e validações exigidos;
6. fazer merge quando as proteções permitirem;
7. validar o resultado após o merge no ramo/deploy alvo;
8. confirmar `git status --porcelain` vazio no workspace da tarefa;
9. confirmar que não ficou PR aberta/draft da própria rodada.

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

### Fonte de verdade operacional para Shopee

Para rotinas Shopee, o sandbox do agente **não é fonte de verdade de credenciais**. A sequência obrigatória de diagnóstico é:

1. consultar a evidência mais recente do workflow `Credential Presence Audit`;
2. considerar `/home/ubuntu/shopvivaliz-deploy/shared/.env` e `shared/runtime-secrets.php` como fontes canônicas do runtime da VM;
3. executar/consultar `Shopee Runtime Health`, que faz leitura real e não mutante do catálogo;
4. só declarar credencial ausente ou inválida quando houver evidência do ambiente operacional real.

O fato de `SHOPEE_ACCESS_TOKEN`/`SHOPEE_REFRESH_TOKEN` não estarem materializados no ambiente GitHub Actions não significa que o runtime Shopee esteja sem tokens. Tokens rotativos podem existir apenas no runtime privado da VM. O workflow de aplicação real deve usar o runtime canônico da VM, sem copiar tokens rotativos para logs ou artefatos.

Da mesma forma, `TINY_*` e `OLIST_*` não são pré-requisitos para o executor canônico `scripts/shopee_production_seo_apply.py`, que conversa diretamente com a Shopee por `scripts/utils/shopee_client.py`. Um agente não deve declarar a otimização Shopee bloqueada apenas porque antigos workflows Tiny/Olist (`fetch-shopee-listings.yml`/`optimize-shopee-listings.yml`) foram removidos.

## Encerramento obrigatório

Ao finalizar uma tarefa, o agente deve verificar PRs abertas relacionadas à própria rodada. Se houver PR antiga, duplicada, obsoleta ou baseada em diagnóstico superado, deve encerrá-la com comentário explicativo. O repositório não deve acumular PRs pendentes de agentes sem um bloqueio ativo e documentado.

Esta política reforça a regra já existente em `AGENTS.md`: **branch → validação → commit → push → PR → checks → merge → validação pós-merge → árvore limpa → zero PR pendente**, sem finalizar rodadas em estados intermediários.
