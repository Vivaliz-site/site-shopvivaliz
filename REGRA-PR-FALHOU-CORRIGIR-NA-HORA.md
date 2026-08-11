# Regra operacional: PR falhou = corrigir na hora

**Vigência:** 2026-08-10  
**Escopo:** todos os agentes e todas as alterações versionadas  
**Norma superior:** `REGRAS-AGENTES-CENTRALIZADAS.md`

> Este arquivo não cria uma política paralela. Ele torna executável a regra central: qualquer erro interrompe a rotina, nenhuma entrega pode ser declarada sem evidência e toda rodada versionada deve terminar em Commit → PR → Merge quando não houver bloqueio externo real.

## Regra inviolável

**PR verde não pode permanecer aberto.**

Um agente não pode encerrar, abandonar, trocar de tarefa ou declarar sucesso enquanto existir PR de sua rodada que esteja tecnicamente concluível. A existência de um PR aberto é um estado de trabalho em andamento, nunca evidência de entrega.

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
5. Se `main` avançar, atualizar o branch do PR antes do merge e executar novamente os gates no novo SHA.
6. Quando todos os gates aplicáveis estiverem verdes no SHA atual e o branch contiver a `main` atual, fazer o **merge imediatamente**.
7. Após o merge, validar o efeito publicado/deploy quando a mudança exigir essa verificação.

## Execução automática obrigatória

A regra textual é reforçada por três componentes versionados e auditáveis. O GitHub Actions atua apenas como orquestrador de leitura; credenciais Gemini e autenticação GitHub de escrita permanecem privadas dentro da Oracle VM.

### `PR Conflict Auto-Healer`

- roda em eventos do PR e também em varredura periódica;
- atua somente em PRs não-draft do próprio repositório com base `main`;
- sincroniza `main` no branch do PR mesmo quando não existe conflito;
- quando existe conflito Git, usa Gemini para produzir a resolução;
- executa o resolvedor dentro da Oracle, lendo somente os nomes de credenciais Gemini permitidos de `/home/ubuntu/shopvivaliz-deploy/shared/.env`;
- utiliza todas as **credenciais Gemini** únicas configuradas nos aliases e bundles suportados, deduplicando-as e alternando automaticamente quando uma chave retorna quota/rate limit, autenticação inválida ou indisponibilidade;
- nunca copia valores Gemini para o runner do Actions e nunca imprime valores de chaves;
- nunca executa código do PR enquanto possui acesso ao runtime privado;
- rejeita binários, symlinks, arquivos excessivamente grandes e qualquer resultado que ainda contenha marcadores de conflito;
- publica somente no branch original do PR, nunca `main`/`master`, nunca com force-push e somente se o SHA remoto ainda for o esperado;
- o push é feito pela autenticação GitHub externa já configurada na Oracle, portanto gera um novo SHA e deve disparar novamente os gates normais do PR.

### `PR Completion Enforcer`

- reavalia PRs sempre que um gate canônico termina e também em varredura periódica;
- usa `cancel-in-progress` para descartar avaliações obsoletas e evitar fila redundante de eventos equivalentes;
- exige que o SHA validado contenha a `main` atual;
- exige sucesso dos gates canônicos no **mesmo SHA** que será mesclado;
- rejeita qualquer SHA com workflow conhecido em estado de falha;
- confere novamente o SHA no runner e a Oracle repete estado, repositório, base, SHA e ancestralidade de `main` imediatamente antes do merge;
- faz squash merge por meio da autenticação GitHub externa da Oracle, sem copiar token de escrita para Actions;
- quando `main` avança, PRs restantes ficam inelegíveis até o próximo ciclo do Auto-Healer sincronizar e revalidar cada novo SHA.

### `PR Policy Enforcement`

- é gate obrigatório de todo PR;
- testa a rotação de credenciais Gemini, fallback de modelo, leitura seletiva do `.env` privado e rejeição de marcadores de conflito;
- verifica que os workflows e scripts responsáveis por esta política continuam presentes e com os invariantes mínimos;
- proíbe reintroduzir `GH_REPO_TOKEN`, chaves Gemini diretamente no workflow, `git push` direto no Actions ou merge direto pelo runner;
- qualquer tentativa de remover ou enfraquecer essa automação deve deixar o próprio PR vermelho.

## SLA operacional obrigatório

- **Conflito detectado:** tentativa automática no evento do PR; varredura adicional no máximo a cada 10 minutos.
- **PR com `main` desatualizada:** sincronização automática e revalidação; checks antigos nunca autorizam merge.
- **PR verde, atualizado e sem bloqueio externo:** merge automático no evento do gate ou, no máximo, no próximo ciclo de 5 minutos do `PR Completion Enforcer`.
- **Pool Gemini esgotado:** a rodada continua `FALHOU`/`INCONCLUSIVO`; o PR não pode ser declarado concluído e a próxima varredura tenta novamente.
- **Autenticação GitHub da Oracle indisponível:** falhar fechado; nenhum fallback para force-push, bypass ou token impresso é permitido.

## É proibido

- Encerrar uma rodada com PR aberto apenas porque um teste falhou.
- Classificar falha conhecida como “baseline”, “dívida técnica” ou “resolver depois” para evitar a correção no ciclo atual.
- Abrir um PR de correção e deixar o PR original quebrado sem substituição/fechamento explícito.
- Deixar draft abandonado, branch remota órfã ou PR verde sem merge quando existe acesso técnico para concluir.
- Declarar sucesso enquanto existir gate vermelho, teste não executado ou validação obrigatória pendente.
- Aceitar como válidos checks executados em SHA anterior ao SHA que será mesclado.
- Fazer force-push, bypass de branch protection, reduzir cobertura ou enfraquecer teste apenas para obter verde.
- Expor secrets a PRs de forks ou executar código não confiável com secrets disponíveis.
- Copiar o pool privado Gemini ou a autenticação GitHub de escrita da Oracle para logs, artifacts, outputs ou argumentos públicos do Actions.
- Usar IA para apagar testes, validações, segurança, tratamento de erros ou proteções apenas para resolver conflito.

## Exceção válida: bloqueio externo real

`INCONCLUSIVO` só é permitido quando existe impedimento externo que o agente e a automação não conseguem corrigir tecnicamente no ciclo atual, por exemplo:

- falta de permissão exigida pela plataforma;
- branch protection que exige uma ação humana não disponível;
- indisponibilidade comprovada de serviço externo necessário;
- todas as credenciais de um provedor estão simultaneamente sem quota/indisponíveis e não existe fallback autorizado;
- credencial que precisa ser emitida/rotacionada pelo proprietário ou fornecedor.

Nesse caso, o agente deve registrar a evidência do bloqueio, o SHA/PR afetado, os checks observados e a ação externa exata necessária. `INCONCLUSIVO` não pode ser usado para adiar correção de código, teste, workflow ou configuração que esteja ao alcance técnico do agente.

## Regra curta para agentes

**Falhou → diagnostica → corrige na hora → revalida.  
Conflitou → Auto-Healer resolve/retenta na Oracle → revalida no novo SHA.  
Ficou verde e contém a main atual → merge na hora pela Oracle.  
Só termina aberto se houver bloqueio externo comprovado.**
