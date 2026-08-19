# Política PR: falhou, corrigir, validar e mergear no mesmo ciclo

**Efetivo:** 2026-08-18  
**Escopo:** todos os agentes e automações que criam, alteram, validam ou finalizam pull requests do ShopVivaliz.

## Regra obrigatória

Um check vermelho, conflito, erro de execução ou validação incompleta não autoriza deixar o PR pendente e encerrar a tarefa. O agente responsável deve permanecer no mesmo ciclo operacional até:

1. abrir o log exato da execução que falhou;
2. identificar a causa raiz verificável;
3. corrigir o erro na mesma branch do PR;
4. sincronizar a branch com a `main` atual sem force-push;
5. executar novamente as validações reais aplicáveis;
6. repetir o ciclo enquanto houver falha corrigível;
7. fazer o merge imediatamente quando todos os checks atuais estiverem verdes.

## Resultado permitido

- **MERGEADO:** todos os checks atuais passaram, a branch estava sincronizada e o merge foi confirmado.
- **INCONCLUSIVO:** somente quando existe bloqueio externo comprovado que o agente não consegue corrigir tecnicamente, como indisponibilidade do provedor, permissão ausente, proteção de branch ou credencial administrada por terceiro. Deve incluir evidência, responsável externo e próxima ação concreta.

`INCONCLUSIVO` não pode ser usado para erro de código, YAML, teste, conflito, lint, documentação, workflow temporário, migração, configuração versionada ou outro defeito que o agente consiga corrigir no repositório.

## Proibições

- não encerrar com “PR pronto”, “aguardando checks” ou “pode ser mergeado”;
- não ignorar check vermelho;
- não fazer bypass de proteção, force-push ou merge com validação vencida;
- não substituir execução real por simulação, mock ou raciocínio teórico;
- não abrir outro PR para fugir do erro do PR atual;
- não atribuir falha global/preexistente sem corrigi-la quando ela bloqueia o merge atual;
- não repetir automaticamente a mesma execução sem antes ler o log e aplicar correção quando a falha é determinística.

## Validação real mínima

A validação deve corresponder ao risco da alteração:

- código: testes, lint, compilação e execução do caminho afetado;
- workflow: execução do workflow ou validação equivalente e inspeção dos jobs;
- produção: estado antes/depois, serviço, logs e efeito independente;
- interface: navegador real quando a mudança é visual;
- banco: migration real em ambiente apropriado e consulta pós-condição;
- integração: chamada real segura, resposta e persistência observável.

Todos os resultados devem usar timestamps absolutos e um SHA verificável.

## Fluxo do finalizador

O finalizador automático só pode mergear quando:

- o PR pertence ao mesmo repositório;
- a base é `main`;
- a branch contém a `main` atual;
- todos os gates obrigatórios no SHA atual estão `completed:success`;
- não existe qualquer execução concluída em falha no mesmo SHA;
- o SHA não mudou entre a inspeção e a mutação.

Quando encontrar falha concluída, o finalizador deve registrar os nomes e URLs das execuções que falharam e marcar o PR como `repair-required-now`. O agente responsável deve corrigir imediatamente; o finalizador não deve tratar o PR como concluído nem silenciosamente esquecê-lo.

## Conflitos

Conflitos são erros corrigíveis. O agente deve:

1. atualizar a branch com a `main` atual;
2. resolver semanticamente cada conflito;
3. validar os dois comportamentos envolvidos;
4. enviar o novo SHA;
5. aguardar as validações desse SHA;
6. mergear quando verde.

Nunca usar `git reset --hard`, descartar alterações sem análise ou reescrever histórico protegido.

## Evidência final obrigatória

```text
PR=<numero/url>
HEAD_SHA=<sha validado>
MAIN_SHA_BEFORE=<sha>
FAILED_RUNS_REPAIRED=<lista ou none>
REQUIRED_CHECKS=all_success
REAL_VALIDATION=<evidência>
MERGE_SHA=<sha>
MERGED_AT=<timestamp absoluto>
RESULT=MERGEADO|INCONCLUSIVO
```
