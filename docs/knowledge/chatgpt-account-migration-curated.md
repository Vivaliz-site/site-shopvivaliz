# Migração curada de contexto ChatGPT — ShopVivaliz

Este documento define o pacote canônico de migração de contexto entre contas ChatGPT da ShopVivaliz.

Objetivo: permitir que uma conta nova retome o trabalho com o mesmo contexto operacional necessário, sem importar ruído, conversas irrelevantes, credenciais ou estados históricos não verificados.

## Princípio

A migração é **curada**, não uma cópia integral do histórico.

A conta nova deve receber:
- conhecimento durável;
- decisões finais e regras vigentes;
- mapas de projetos, hosts e ferramentas;
- incidentes com lição reutilizável;
- tarefas abertas relevantes, quando ainda confirmadas;
- referências para evidência canônica.

A conta nova não deve receber:
- chats casuais;
- perguntas pontuais já resolvidas sem valor futuro;
- tentativas repetidas que não produziram decisão;
- mensagens duplicadas;
- raciocínio intermediário que foi substituído por decisão posterior;
- senha, token, chave privada, cookie, OTP/TOTP, seed ou conteúdo de secret;
- estado operacional antigo apresentado como se ainda fosse atual.

## Hierarquia de confiança

Ao iniciar trabalho, usar esta ordem:

1. evidência viva no runtime, provider, produção ou serviço afetado;
2. código e configuração no repositório canônico;
3. documentação canônica em `docs/knowledge/`;
4. decisões históricas selecionadas;
5. chats migrados apenas como contexto auxiliar.

Se um chat histórico divergir de evidência viva ou da documentação canônica atual, o chat histórico perde precedência.

## Bootstrap mínimo da conta `dev`

A conta `dev@shopvivaliz.com.br` deve ler nesta ordem:

1. `docs/knowledge/dev-chatgpt-bootstrap.md`;
2. `docs/knowledge/host-access.md`;
3. `docs/knowledge/README.md`;
4. `docs/knowledge/agent-rules.md`;
5. `docs/knowledge/project.md`;
6. `docs/knowledge/browser-sessions.md`;
7. `docs/knowledge/chatgpt-account-migration-curated.md`;
8. `docs/knowledge/dev-agent-briefing.md`;
9. `docs/knowledge/dev-context-transfer-20261005.md`;
10. documentação específica do projeto ou rotina afetada.

## Conhecimento durável que deve ser preservado

### Repositórios canônicos

- `Vivaliz-site/site-shopvivaliz` — site e base operacional principal.
- `Vivaliz-site/amazon-returns-safet` — Amazon Returns / SAFE-T.
- `Vivaliz-site/mercadolivre-returns-recovery` — devoluções Mercado Livre.
- `Vivaliz-site/-shopvivaliz-pipeline` — pipeline e automações.
- `fredmourao-ai/mei-mg-email` — automação MEI-MG.
- `fredmourao-ai/solange-rolla-consultorio` — repositório canônico Solange Rolla.
- `fredmourao-ai/solange-rolla` — legado/supersedido; não usar como canônico.

### Hosts e papéis

A fonte final é `host-access.md`. O mapa atual documentado é:

- `shopvivaliz-free-a1` — produção web/deploy.
- `always-free-arm-1787907847-26` — backend/controller/browser/MEI/M365/relay.
- `shopvivaliz-ai` — legado DEV/e-mail/testes; nunca assumir como produção web.

Produção usa `/home/ubuntu/shopvivaliz-deploy/` com releases imutáveis. Nunca editar diretamente `current/` ou a release ativa.

### Acesso e ferramentas

- Remote Control MCP é a rota operacional primária para hosts, serviços, navegador, arquivos e tarefas duráveis.
- SSH privado/Tailscale é fallback para capacidade não exposta ou indisponibilidade comprovada do MCP.
- GitHub Actions/OCI Bastion são bootstrap/recovery, não transporte normal do runtime.
- Navegador operacional da ShopVivaliz roda na VM backend, não nos hosts Windows.
- Estado real deve ser confirmado por evidência fresca antes de concluir.

### Sessões ChatGPT

A fonte final é `browser-sessions.md`.

- `shopvivaliz-dev-chromium` / CDP 9559 — conta corporativa Dev.
- `shopvivaliz-chromium` / CDP 9555 — legado temporário apenas para checkpoints pré-migração já vinculados; não usar para novas tarefas.
- `shopvivaliz-atendimento-chromium` / CDP 9556 — conta corporativa Atendimento.
- Nunca fazer logout para trocar contas.
- Nunca reutilizar perfil, cookies, storage ou porta CDP entre contas.
- Se uma sessão falhar, reparar o perfil correto; não usar o outro como atalho.

### Regra de auditoria e diagnóstico

Auditoria e diagnóstico não terminam em relatório.

Fluxo obrigatório:
`confirmar -> investigar causa raiz -> corrigir -> prevenir -> testar -> validar runtime/E2E -> revalidar`.

Enquanto existir correção segura e executável, a tarefa permanece em andamento.

### Continuidade

Uma tarefa iniciada só é terminal quando:
- `CONCLUIDO` com validação fresca; ou
- `BLOCKED_EXTERNAL` com bloqueio objetivo, comprovado e sem alternativa segura restante.

Falha de ferramenta, navegador, sessão, runner, timeout ou rota primária não é, por si só, estado terminal.

### Segurança

- Nunca migrar conteúdo de credenciais.
- Documentar apenas nome da fonte segura, localização autorizada ou procedimento.
- Nunca guardar senha, token, chave, cookie, OTP/TOTP, seed ou secret em Git, docs de bootstrap ou memória textual.
- Não assumir que um plugin instalado está autenticado; validar por chamada real quando necessário.

## Contexto de projeto que merece migração

### Amazon Returns / SAFE-T

Preservar:
- regras de elegibilidade e encerramento;
- necessidade de reconciliação financeira antes de encerramento real;
- decisões arquiteturais multi-tenant;
- incidentes de UI/bridge que produziram correções reutilizáveis;
- critérios de validação E2E.

Não preservar como verdade atual:
- contagem antiga de casos;
- fila antiga;
- status de bridge/health de um dia específico;
- bloqueios históricos já resolvidos.

Esses valores devem ser consultados novamente no sistema atual.

### Mercado Livre Returns

Preservar:
- arquitetura de ingestão, reconciliação e projeção idempotente;
- distinção entre falha local e bloqueio externo de provider;
- critérios de E2E real.

Revalidar sempre qualquer 403, autorização de Claims/Returns/Billing e estado de app.

### MEI-MG Email

Preservar:
- stop-the-line por bounce;
- regra de não reiniciar worker enquanto pausa operacional estiver ativa;
- monitoramento e reconciliação como parte do critério de retomada.

Taxas, contagens e estado do worker são voláteis e devem ser medidos novamente.

### Solange Rolla

Preservar:
- `fredmourao-ai/solange-rolla-consultorio` como repositório canônico;
- repositório `fredmourao-ai/solange-rolla` como legado;
- necessidade de validação funcional real, não apenas testes estruturais.

### Site ShopVivaliz / automações

Preservar:
- releases imutáveis;
- auditoria funcional real;
- política de continuidade;
- regras de Git/PR/merge/deploy;
- princípio de não aceitar falso-verde;
- arquitetura de roteamento de automações somente quando confirmada pelo repositório atual.

## Histórico selecionado que vale manter

Um chat ou resumo histórico só entra no pacote quando satisfizer pelo menos um destes critérios:

1. definiu uma regra durável;
2. resolveu causa raiz de incidente relevante;
3. mudou arquitetura ou processo;
4. registrou decisão de segurança, deploy ou autenticação;
5. contém evidência necessária para entender uma correção ainda existente;
6. contém tarefa aberta que ainda foi confirmada como atual;
7. evita investigação repetitiva de um problema já compreendido.

Quando houver várias conversas sobre o mesmo problema, migrar apenas a decisão final e a evidência essencial.

## Histórico que deve ser descartado

Excluir:
- chats sem relação com projetos ou operação futura;
- compras, dúvidas gerais e assuntos pessoais sem impacto no trabalho;
- solicitações repetidas de “continue”, “siga” ou “retome” sem nova informação;
- tentativas de login que foram substituídas por um procedimento atual;
- diagnósticos preliminares refutados;
- status temporários antigos;
- dumps extensos de logs sem valor explicativo;
- mensagens contendo segredos, mesmo se tecnicamente úteis.

## Estrutura recomendada do pacote

O pacote lógico da conta nova deve ser entendido como:

```text
bootstrap/
  dev-chatgpt-bootstrap.md
  chatgpt-account-migration-curated.md
knowledge/
  host-access.md
  agent-rules.md
  project.md
  browser-sessions.md
  dev-agent-briefing.md
project-state/
  referencias para estado vivo por projeto
history/
  decisoes-curadas
  incidentes-relevantes
  tarefas-abertas-confirmadas
```

Não é necessário duplicar os arquivos fisicamente se todos estiverem acessíveis no repositório. O objetivo é ter uma ordem de leitura e um índice estável.

## Formato para decisões históricas migradas

Use este formato ao consolidar uma conversa relevante:

```text
DECISAO
Data:
Projeto:
Problema:
Decisão final:
Motivo:
Evidência/referência:
Ainda vigente?: SIM / REVALIDAR
Substitui:
```

Evitar copiar a conversa inteira quando uma decisão consolidada for suficiente.

## Formato para tarefa aberta migrada

```text
TAREFA
Projeto:
Objetivo:
Última evidência confirmada:
Estado:
Próxima ação:
Critério de conclusão:
Fontes canônicas:
```

Toda tarefa migrada deve ser revalidada antes de execução. Não usar uma tarefa histórica como prova de que o problema ainda existe.

## Prompt de bootstrap da nova conta

```text
Use Vivaliz-site/site-shopvivaliz (main) como fonte primária da ShopVivaliz.

Leia primeiro:
1. docs/knowledge/dev-chatgpt-bootstrap.md
2. docs/knowledge/host-access.md
3. docs/knowledge/README.md
4. docs/knowledge/agent-rules.md
5. docs/knowledge/project.md
6. docs/knowledge/browser-sessions.md
7. docs/knowledge/chatgpt-account-migration-curated.md
8. docs/knowledge/dev-agent-briefing.md
9. docs/knowledge/dev-context-transfer-20261005.md

O histórico importado é curado e serve apenas como contexto auxiliar. Não trate status históricos como atuais sem revalidar. Nunca exponha secrets. Quando houver divergência, priorize evidência viva, código atual e documentação canônica. Continue tarefas até validação real ou bloqueio externo comprovado.
```

## Validação da migração

A migração é considerada funcional quando uma conversa nova da conta destino consegue, sem depender da conta antiga:

1. identificar os repositórios canônicos;
2. distinguir produção, backend e host legado;
3. explicar a política de releases imutáveis;
4. selecionar a sessão de navegador correta para a conta;
5. localizar a regra de auditoria corretiva;
6. explicar o critério terminal de continuidade;
7. localizar a documentação específica de SAFE-T, Mercado Livre, MEI-MG e Solange;
8. distinguir contexto histórico de estado vivo;
9. operar sem solicitar novamente secrets já provisionados, salvo prova de ausência/invalidez;
10. executar um teste inofensivo com evidência fresca quando houver ferramenta autorizada disponível.

## Manutenção

Quando uma regra ou decisão mudar:
- atualizar primeiro a fonte canônica específica;
- atualizar este documento somente se a mudança alterar a política de migração;
- não acumular status diários aqui;
- remover referências superadas em vez de empilhar versões contraditórias.

A meta é que a nova conta carregue **menos informação, porém mais confiável** do que o histórico bruto.
