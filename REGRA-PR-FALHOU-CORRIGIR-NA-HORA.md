# Regra operacional: PR falhou = corrigir na hora

**Vigência:** 2026-08-10  
**Escopo:** todos os agentes e todas as alterações versionadas  
**Norma superior:** `REGRAS-AGENTES-CENTRALIZADAS.md`

> Este arquivo não cria uma política paralela. Ele torna explícita a execução conjunta das regras já existentes na fonte central: qualquer erro interrompe a rotina, nenhuma entrega pode ser declarada sem evidência e toda rodada versionada deve terminar em Commit → PR → Merge quando não houver bloqueio externo real.

## Ciclo obrigatório

1. Fazer a alteração e gerar o commit correspondente.
2. Abrir ou atualizar o PR.
3. Executar todos os gates, testes e validações aplicáveis.
4. Se **qualquer gate falhar**, o agente deve:
   - tratar o resultado como **FALHOU**;
   - identificar a causa concreta pelo log/teste;
   - corrigir imediatamente no mesmo branch/PR sempre que tecnicamente possível;
   - executar novamente os gates afetados e os gates obrigatórios;
   - repetir **corrigir → revalidar** até não existir falha conhecida.
5. Quando todos os gates aplicáveis estiverem verdes, fazer o **merge imediatamente** se as proteções e permissões permitirem.
6. Após o merge, validar o efeito publicado/deploy quando a mudança exigir essa verificação.

## É proibido

- Encerrar uma rodada com PR aberto apenas porque um teste falhou.
- Classificar falha conhecida como “baseline”, “dívida técnica” ou “resolver depois” para evitar a correção no ciclo atual.
- Abrir um PR de correção e deixar o PR original quebrado sem substituição/fechamento explícito.
- Deixar draft abandonado, branch remota órfã ou PR verde sem merge quando o agente possui acesso para concluir.
- Declarar sucesso enquanto existir gate vermelho, teste não executado ou validação obrigatória pendente.
- Fazer force-push, bypass de branch protection, reduzir cobertura ou enfraquecer teste apenas para obter verde.

## Exceção válida: bloqueio externo real

`INCONCLUSIVO` só é permitido quando existe impedimento externo que o agente não consegue corrigir tecnicamente no ciclo atual, por exemplo:

- falta de permissão exigida pela plataforma;
- branch protection que exige uma ação humana não disponível;
- indisponibilidade comprovada de serviço externo necessário;
- credencial que precisa ser emitida/rotacionada pelo proprietário ou fornecedor.

Nesse caso, o agente deve registrar a evidência do bloqueio, o SHA/PR afetado, os checks observados e a ação externa exata necessária. `INCONCLUSIVO` não pode ser usado para adiar correção de código, teste, workflow ou configuração que esteja ao alcance técnico do agente.

## Regra curta para agentes

**Falhou → diagnostica → corrige na hora → revalida.  
Ficou verde → merge na hora.  
Só termina aberto se houver bloqueio externo comprovado.**
