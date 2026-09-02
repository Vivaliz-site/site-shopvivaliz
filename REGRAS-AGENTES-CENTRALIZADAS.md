# 📋 REGRAS PARA AGENTES IA - FONTE ÚNICA CENTRALIZADA

**Efetivo:** 2026-07-24  
**Escopo:** Todos os agentes (Claude, Codex, Gemini, GPT, etc.)  
**Aplicável a:** Qualquer tarefa automatizada (deploy, testes, integrações, ERP, pagamentos, emails, secrets)  
**Objetivo:** Eliminar falsos positivos, exigir evidência verificável antes de declarar sucesso

> ⚠️ **ESTA É A FONTE ÚNICA DE VERDADE PARA TODAS AS REGRAS.**  
> Outros arquivos (VALIDATION-POLICY.md, SECRETS-SYNC-RULE.md, etc.) são DEPRECADOS.  
> Veja [Referências Cruzadas](#referências-cruzadas) para documentação específica.

---

## ✅ AUTORIZAÇÃO OPERACIONAL DO PROPRIETÁRIO (2026-08-01)

O proprietário autoriza os agentes que tenham capacidade técnica e acesso válido a:

- validar a entrega de ponta a ponta em navegador real, incluindo interação, persistência e evidência visual;
- deixar a mudança pronta para revisão com resumo, riscos e evidências verificáveis;
- aprovar pull requests, fazer merge e acionar ou executar o deploy sem aguardar uma nova aprovação explícita do proprietário, desde que os checks, as proteções e os critérios do repositório permitam a ação;
- acompanhar o gate e a produção até obter evidência do resultado.

Fluxo padrão: depois de validar a entrega e deixá-la pronta para revisão, o agente conclui a aprovação/merge autorizado e acompanha o Quality Gate, o deploy automático e o smoke test até o resultado final. Não deve interromper o fluxo para pedir confirmação intermediária.

Esta autorização remove apenas a espera por uma aprovação adicional. Ela não autoriza force-push, bypass de branch protection, exposição de secrets, cobrança real, exclusão destrutiva de dados ou declaração de sucesso sem evidência. Antes de merge/deploy, o agente deve confirmar o SHA alvo, os checks do PR, a validação real aplicável e, quando houver alteração publicada, a release ativa, os logs e o smoke test de produção. Se a plataforma bloquear autoaprovação ou outra etapa, o agente não deve contornar a proteção: deve registrar o bloqueio como **INCONCLUSIVO**.

## 🛡️ SEGURANÇA DE AUTOMAÇÃO DE UI E AÇÕES DESTRUTIVAS (2026-09-01)

Autonomia, conclusão ponta a ponta e autorização operacional **não ampliam o escopo destrutivo**. Exclusão, remoção, reset, revogação ou outra ação irreversível só é permitida quando **o alvo e o resultado destrutivo exatos** estiverem no pedido atual do proprietário. Pedidos genéricos como investigar, auditar, limpar, corrigir, concluir tudo ou fazer o necessário não autorizam apagar projetos, chats, contas ou dados por inferência.

- **Probe, diagnóstico, auditoria, health check e observação são somente leitura.** É proibido invocar `Excluir`, `Delete`, `Remove`, `Reset`, `Revogar` ou confirmação destrutiva em dados reais durante esse tipo de tarefa.
- Se for indispensável testar exclusão, use fixture/sandbox criada especificamente para o teste; nunca um projeto, chat, conta ou dado real do proprietário.
- É proibido criar task agendada, serviço, script oculto ou processo elevado para contornar limites de permissão de ferramenta ou executar automação destrutiva de UI.
- Tasks temporárias de UI não podem permanecer ativas após a tarefa e não devem usar `RunLevel Highest` como ponte para interação com aplicações do usuário.
- Nomes devem descrever o efeito real. Um payload que altera estado nunca pode ser rotulado como `probe`, `check`, `audit` ou equivalente somente-leitura.
- Antes de atribuir uma execução a terceiro, o agente deve correlacionar timestamps com **suas próprias chamadas de ferramenta e logs**. É proibido reportar como atividade externa uma ação que o próprio agente disparou.
- `LastResult=0`, exit code 0 ou ausência de exceção comprovam apenas término técnico; não comprovam o efeito de negócio nem justificam inferir que uma exclusão foi concluída.
- Em incidente, preserve evidência e hashes antes de limpar artefatos. O material preservado deve ficar fora de caminhos de execução automática.

Estas regras prevalecem sobre qualquer protocolo de autonomia, insistência até conclusão ou orientação para não pedir confirmação.

---
### Commit, PR e Merge obrigatório ao finalizar rodada de alterações

> ⚠️ **REGRA DE FINALIZAÇÃO OBRIGATÓRIA:**
> Qualquer alteração feita no repositório, ao ser finalizada, deve obrigatoriamente ser validada e seguir o fluxo de:
> 1. **Commit** das alterações locais.
> 2. Abertura ou atualização do **Pull Request (PR)** correspondente.
> 3. Realização de **Merge** para a branch principal ou branch ativa de entrega.
> 
> Toda alteração deve ser validada pelo navegador de forma visual e funcional (nada de scripts para essa validação) e seguir este fluxo. Não é aceitável encerrar uma rodada de alterações sem concluir o ciclo de commit, PR e merge/deploy quando houver acesso técnico para isso. Se houver algum impedimento técnico ou bloqueio de proteção de branch, o status deve ser reportado formalmente como **INCONCLUSIVO**.

Ao concluir qualquer rodada de alterações versionadas, o agente deve deixar a mudança integrada por merge no ramo alvo e/ou publicada no alvo de deploy autorizado, desde que checks, revisões e proteções permitam. Não é aceitável encerrar uma rodada como "pronta" mantendo apenas branch local ou remoto sem merge/deploy quando o agente tem acesso técnico para concluir o fluxo. Se branch protection, CI, falta de permissão ou outro gate impedir o merge, o resultado deve ser registrado como **INCONCLUSIVO**, com link/SHA, checks observados e próximo bloqueio concreto.

Simulação não substitui execução real: testes secos, mocks, screenshots headless, `curl` isolado ou inspeção de código são apenas preparação. Para declarar entrega, o agente deve executar a rotina real aplicável e validar o efeito por evidência independente, incluindo navegador real para UI, sem gerar cobrança real, alterar preço/estoque/pedido fora do escopo, expor secrets ou contornar proteções.

---

## 💸 POLÍTICA OBRIGATÓRIA DE CONSUMO DE IA E EXECUÇÃO RECORRENTE (2026-09-01)

Esta política é vinculante para **todos os projetos, hosts, workflows, serviços, timers, cron jobs, Scheduled Tasks, scripts, agentes e rotinas autônomas**.

1. **Rotina permanente, periódica, agendada, daemon, watcher ou loop não pode usar Claude, GPT/OpenAI, Codex ou qualquer outro modelo pago por padrão.** Se IA for realmente necessária nesse tipo de rotina, deve usar primeiro uma opção gratuita/local explicitamente aprovada (por exemplo Ollama/local ou cota gratuita comprovada), com fallback determinístico sem IA. É proibido fazer fallback silencioso de IA gratuita para provedor pago.
2. **Claude/GPT/Codex pagos são permitidos somente para tarefas finitas, iniciadas para atingir um objetivo concreto e que terminem ao concluir ou bloquear de forma real.** Eles não podem permanecer como daemon, supervisor, polling loop, autorepair, watchdog ou consumidor recorrente.
3. **Toda execução finita de IA paga deve ter circuit breaker.** Definir limites coerentes de duração, tentativas/retries, chamadas ao modelo e tamanho de trabalho/contexto. `while true`, retry ilimitado, relançamento automático e continuidade sem teto são proibidos para consumidores pagos. Se o limite for atingido, persistir checkpoint e encerrar; uma nova execução só pode ocorrer por novo evento/tarefa explícita, nunca por loop de consumo.
4. **Antes de habilitar ou manter uma automação, auditar cada consumidor individualmente.** Registrar: arquivo/comando, host, gatilho, frequência, provedor/modelo, se há custo, timeout, retries, limite de chamadas, condição de saída, necessidade real do processo e evidência de que não existe duplicação/órfão. Não assumir que um processo é necessário apenas porque já está instalado ou ativo.
5. **Serviço contínuo só é legítimo quando a natureza do serviço exige continuidade.** Monitoramento, fila, API, renovação de token e health-check devem ser preferencialmente determinísticos. Trabalho periódico finito deve usar timer/cron + `oneshot`, e não processo infinito com `sleep`, quando não houver necessidade de daemon.
6. **Processo travado, órfão ou sem progresso deve ser encerrado e investigado, não reiniciado indefinidamente.** Supervisores devem diferenciar falha transitória de erro persistente e possuir backoff, máximo de reinícios em janela e estado de bloqueio/cooldown.
7. **Eventos de GitHub não podem disparar IA paga de forma ampla.** Workflows com Claude/GPT/Codex devem exigir gatilho explícito e restrito (por exemplo comando/label/dispatch autorizado), ter `timeout-minutes`, `concurrency` e cancelamento de execução obsoleta quando aplicável. Comentários, reviews, issues, pushes ou schedules genéricos não podem consumir IA paga automaticamente.
8. **Fallback deve priorizar custo zero:** determinístico → local/gratuito → pago somente em tarefa finita explicitamente autorizada. Para rotinas recorrentes, a cadeia termina antes do provedor pago.
9. **Teste de credencial não deve consumir modelo sem necessidade.** Preferir validação de formato/configuração/endpoint sem geração; quando uma chamada real for indispensável, ela deve ser manual/finita, mínima e sem repetição automática.
10. **Observabilidade obrigatória:** consumidores de IA devem registrar, sem secrets, pelo menos início/fim, motivo/gatilho, provedor/modelo, quantidade de tentativas, duração, resultado e identificador da tarefa. Onde a API expuser uso, registrar métricas de tokens/custo agregadas. Alertar e bloquear comportamento anômalo.

### Critério de classificação obrigatório

| Tipo | Forma correta | IA paga |
|---|---|---|
| API/worker que precisa ficar online | serviço contínuo, lógica determinística | **PROIBIDA em loop** |
| Verificação periódica | timer/cron + job `oneshot` finito | **PROIBIDA** |
| Watchdog/autorepair | regras determinísticas + backoff/cooldown | **PROIBIDA** |
| Resolução complexa sob demanda | tarefa finita com timeout/budget | Permitida, se necessária |
| Revisão/implementação por agente | execução finita até conclusão, com circuit breaker | Permitida |
| Conflito/triagem automatizada | determinístico ou IA local/gratuita | Paga somente por disparo manual explícito |

**Regra de ouro:** concluir a tarefa não significa deixar o agente rodando. O estado final correto de Claude/GPT/Codex é **processo encerrado** após a tarefa; continuidade operacional pertence a software determinístico ou IA gratuita/local com limites.

## 🎯 PRINCÍPIOS FUNDAMENTAIS (4 REGRAS INVIOLÁVEIS)

### 1. NUNCA declare sucesso sem evidência INDEPENDENTE

**Proibido:**
```
❌ "O webhook deve ter sido enviado"
❌ "Provavelmente funcionou"
❌ "A máquina parecia responder bem"
❌ "Nenhum erro na saída, então deve estar OK"
```

**Obrigatório:**
```
✅ "Webhook confirmado: POST /webhook HTTP 200 às 14:31:08 UTC, body: {...}"
✅ "Pedido verificado no banco: SELECT * FROM orders WHERE id='ABC' ✓ status='approved'"
✅ "Health check respondeu: GET /health HTTP 200 {'status':'up'}"
```

---

### 2. NUNCA considere ação MANUAL como prova de AUTOMAÇÃO

**Proibido:**
```
❌ Executar git pull manualmente e depois afirmar que daemon sincronizou
❌ Criar o arquivo esperado e depois verificar que apareceu
❌ Reiniciar manualmente um serviço e depois afirmar que se recuperou automaticamente
❌ Chamar uma API manualmente e depois declarar que webhook funcionou
```

**Separação obrigatória:**

| Fase | Ação | Responsável | O que Provar |
|------|------|-------------|-------------|
| **Preparação** | Setup, registrar estado anterior | Agente ou Humano | Estado inicial |
| **Disparo** | Criar mudança que deve desencadear automação | Agente ou Humano | Mudança commitada/enviada |
| **Espera** | ⏸️ NÃO FAÇA NADA | Ninguém | Deixar sistema agir |
| **Observação** | Verificar resultado via método **DIFERENTE** | Agente | Prova de efeito automático |

---

### 3. QUALQUER ERRO INTERROMPE A ROTINA

**Obrigatório em todo script:**

```bash
#!/bin/bash
set -Eeuo pipefail  # ← INVIOLÁVEL

git fetch origin    # ← Se isso falha, próximas linhas não rodam
git merge --ff-only # ← Não roda se git fetch falhou
```

---

### 4. RESULTADO SÓ PODE SER: COMPROVADO, FALHOU ou INCONCLUSIVO

**Não existe "parece funcionar", "provavelmente OK", "acho que".**

| Status | Significado | Evidência Mínima |
|--------|------------|------------------|
| ✅ **COMPROVADO** | Prova independente e verificável | SHA bate, log mostra execução |
| ❌ **FALHOU** | Erro confirmado com código/log | Exit code ≠ 0, mensagem de erro |
| ⚠️ **INCONCLUSIVO** | Não conseguiu verificar | Sem acesso a logs, sem SSH |

---

## 🚩 RED FLAGS - PROIBIÇÕES AUTOMÁTICAS

**Se qualquer destes eventos ocorrer, agente fica PROIBIDO de concluir sucesso:**

### Erros Detectados
- ❌ `error:` em qualquer saída
- ❌ `fatal:` em qualquer saída
- ❌ `rejected:`, `denied:`, `timeout`
- ❌ `FileNotFoundError`, `Permission denied`, exceções

### Códigos de Saída
- ❌ Exit code ≠ 0 (qualquer)
- ❌ Comando retornou silenciosamente

### Padrões Perigosos
- ❌ Continuação após erro (`|| echo "OK"`)
- ❌ Supressão de erros (`2>/dev/null` sem justificativa)
- ❌ Forçar sucesso (`|| true` sem lógica)
- ❌ Intervenção manual durante teste de automação

### Dados Insuficientes
- ❌ Sem logs relevantes
- ❌ Sem timestamps
- ❌ Sem comparação antes/depois
- ❌ Sem validação independente

### Inferência (PROIBIDA)
- ❌ "Deve ter funcionado"
- ❌ "Provavelmente OK"
- ❌ "Nenhum erro visto"

---

## 📊 MATRIZ DE EVIDÊNCIAS POR TIPO DE TAREFA

### Deploy de Código
| Componente | Evidência Mínima |
|-----------|-----------------|
| Git | SHA local = SHA remoto; push confirmado |
| SSH/VM | Conexão bem-sucedida; arquivo verificado |
| HTTP | GET / retorna HTTP 200 com conteúdo esperado |
| Logs | Logs de deploy sem erros |

### Git & Sincronização  
| Componente | Evidência Mínima |
|-----------|-----------------|
| Commit | SHA local completo + mensagem |
| Push | git push output confirmando envio |
| Remoto | git ls-remote mostra novo SHA |
| Daemon | Log mostrando git fetch + git merge |
| Confirmação | SHA VM = SHA GitHub (via SSH) |

### Webhook & Callbacks
| Componente | Evidência Mínima |
|-----------|-----------------|
| Envio | HTTP POST confirmado (HTTP 2xx, exit 0) |
| Recepção | Log do servidor mostrando POST recebido |
| Processamento | Webhook handler executado sem erro |
| Persistência | Dados atualizados no banco |
| Validação | Mudança verificada com SELECT ou API |

### API & Integração
| Componente | Evidência Mínima |
|-----------|-----------------|
| Request | HTTP status 2xx |
| Response | JSON/XML com chaves esperadas |
| Idempotência | Mesma request 2x retorna mesmo resultado |
| Persistência | Dado criado + SELECT confirmação |

### Banco de Dados
| Componente | Evidência Mínima |
|-----------|-----------------|
| INSERT | Execute; validar exit 0 |
| Confirmação | SELECT retorna row criado |
| Idempotência | INSERT duplicado trata apropriadamente |

### Pagamento (Mercado Pago, etc.)
| Componente | Evidência Mínima |
|-----------|-----------------|
| Webhook | POST recebido; HTTP 200; log servidor |
| Signature | HMAC-SHA256 validado |
| Order Status | UPDATE confirmado |
| Email | SMTP aceitou; verificar INBOX |
| ERP | GET /orders mostra dados refletidos |

### E-mail
| Componente | Evidência Mínima |
|-----------|-----------------|
| SMTP | Connection accepted; AUTH OK; RCPT OK |
| Envio | SMTP 250 Message accepted |
| Entrega | Verificar INBOX no destino |
| Conteúdo | Subject, to, body corretos |

### ERP & Olist & Shopee
| Componente | Evidência Mínima |
|-----------|-----------------|
| Autenticação | API key aceita; sem key → 401 |
| Sincronização | GET /orders → HTTP 200 |
| Criação | POST → 201 com ID único |
| Confirmação | Dado aparece em ERP via API |
| Idempotência | Sem duplicação com idempotency_key |

### Agente IA (Automação 24/7)
| Componente | Evidência Mínima |
|-----------|-----------------|
| Execução | Log mostrando agente iniciado |
| Ação | Agente executou operação (em log) |
| Efeito | Mudança real observada |
| Validação | Efeito verificado independentemente |
| Erro | Qualquer erro em log; agente não continua |

### Alterações de Interface (UI/UX) / Frontend
| Componente | Evidência Mínima | Responsabilidade |
|-----------|-----------------|------------------|
| Renderização | **SCREENSHOT REAL** no browser (não simulado) | **AGENTE + USUÁRIO** |
| Responsividade | **SCREENSHOT REAL** em Desktop e Mobile | **AGENTE + USUÁRIO** |
| Estilo/Layout | **SCREENSHOT REAL** sem quebras, imagens OK | **AGENTE + USUÁRIO** |
| Interações (JS) | **SCREENSHOT REAL** após interação | **AGENTE + USUÁRIO** |
| CSS/Cor Específica | **SCREENSHOT REAL** mostrando cor correta | **AGENTE + USUÁRIO** |

**⚠️ OBRIGATÓRIO - REGRA INVIOLÁVEL (UI):**

```
NÃO ACEITO validação teórica ou simulada:
  ❌ curl + grep (pode estar em cache, pode não renderizar igual)
  ❌ headless browser screenshots (falta interação real, fonts podem não carregar)
  ❌ "o código está certo, deve funcionar" (inferência, não evidência)

ACEITO APENAS:
  ✅ Screenshot REAL de navegador REAL (Chrome, Firefox, Safari)
  ✅ Navegador com cache limpo (Ctrl+Shift+Delete)
  ✅ Modo normal + modo anônimo (ambos)
  ✅ Desktop + Mobile (se aplicável)
  ✅ Mostrando a mudança de forma inequívoca

PROCESSO OBRIGATÓRIO:
1. Agente faz curl/grep para validação teórica
2. Agente PEDE ao usuário: "Tire screenshot do navegador real"
3. Usuário tira screenshot e envia
4. Agente VERIFICA screenshot visualmente
5. Só então agente declara: SUCESSO ✅
```

**Se Playwright/Selenium não disponível no servidor:** Agente declara INCONCLUSIVO e pede screenshot real.

---

## 🔐 SINCRONIZAÇÃO OBRIGATÓRIA DE SECRETS (3 AMBIENTES)

### Regra Crítica
> **CRÍTICO**: Toda alteração de secret DEVE ser sincronizada em TODOS os 3 ambientes simultaneamente.  
> **Nunca** deixar um secret desincronizado por mais de 5 minutos.

### Quando Aplica
**OBRIGATÓRIO sincronizar quando:**
- ✅ Adicionar novo secret
- ✅ Atualizar valor de secret (rotação)
- ✅ Remover secret deprecado
- ✅ Renovar token expirado

**Exemplo de NÃO sincronizar = ERRO:**
```
❌ Atualizar OLIST_REFRESH_TOKEN só em GitHub
❌ Adicionar NOVO_API_KEY só no local
❌ Rotacionar MERCADOPAGO_TOKEN só na VM
```

### Checklist de Sincronização

#### Passo 1: EDITAR
```bash
# Local: C:\Users\FRED\site-shopvivaliz\.env (ou C:\Users\user\...)
vi .env
```

#### Passo 2: COPIAR para VM
```bash
# Dependendo do perfil do Windows ativo (FRED ou user)
SSH_KEY="C:\Users\user\Downloads\ssh-key-2026-07-04.key"  # ou FRED
scp -i "$SSH_KEY" .env ubuntu@137.131.156.17:/home/ubuntu/site-shopvivaliz/.env
```

#### Passo 3: ATUALIZAR GitHub
```bash
# Via GitHub CLI
gh secret set NOME_SECRET --body "valor"

# OU: Via web
# https://github.com/Vivaliz-site/site-shopvivaliz/settings/secrets/actions
```

#### Passo 4: VALIDAR nos 3 locais
```bash
# Local
grep "NOME_SECRET" C:\site-shopvivaliz\.env

# VM
ssh -i "$SSH_KEY" ubuntu@137.131.156.17 "grep NOME_SECRET /home/ubuntu/site-shopvivaliz/.env"

# GitHub
gh secret list --repo Vivaliz-site/site-shopvivaliz | grep NOME_SECRET
```

#### Passo 5: COMMITAR
```bash
git commit -m "chore: atualizar NOME_SECRET (sincronizado em 3 ambientes)"
git push origin main
```

### Matriz de Sincronização

| Secret | Local | VM | GitHub | Notas |
|--------|-------|-----|--------|-------|
| **Database** | ✅ | ✅ | ❌ | Nunca em GitHub (risco) |
| **Email/SMTP** | ✅ | ✅ | ✅ | Seguro em GitHub |
| **APIs IA** | ✅ | ✅ | ✅ | Sincronizar sempre |
| **ERP/Commerce** | ✅ | ✅ | ✅ | Sincronizar sempre |
| **Deploy/FTP** | ✅ | ❌ | ✅ | Apenas Local+GitHub |
| **CloudFlare** | ✅ | ❌ | ✅ | Apenas Local+GitHub |

### O que Quebra se Não Sincronizar

| Cenário | Impacto | Severidade |
|---------|---------|-----------|
| **Atualizar só Local** | VM usa valor antigo → Erro 401 em produção | 🔴 CRÍTICO |
| **Atualizar só VM** | GitHub CI falha → Deploy quebrado | 🔴 CRÍTICO |
| **Atualizar só GitHub** | Local testa com valor errado | 🟡 MÉDIO |
| **Desatualizar 2/3** | Inconsistência impossível debugar | 🔴 CRÍTICO |

### SOS: Descobriu Desincronização?

**Ação imediata:**

```bash
# 1. Verificar qual está correto
gh secret list  # GitHub é fonte de verdade
ssh -i key.pem ubuntu@137.131.156.17 "grep NOME .env"  # Compare

# 2. Copiar do correto para os outros
# Se GitHub tá certo:
gh secret get NOME > valor.txt
scp valor.txt ...

# 3. Commitar estado correto
git commit -m "fix: sincronizar secrets desincronizados (SOURCE: GitHub)"
```

---

## 🛑 PROIBIÇÃO DE INFERÊNCIA

**Nunca conclua que algo ocorreu porque "deveria ter ocorrido". VERIFIQUE.**

❌ "O webhook foi enviado com sucesso" (sem ver log servidor)
❌ "A mudança deve ter sincronizado" (sem verificar SHA na VM)
❌ "Nenhum erro visto, então funcionou" (ausência ≠ sucesso)
❌ "O agente deve ter executado" (onde está o log?)
❌ "API respondeu 200, deve estar funcionando" (validar corpo também)
❌ "O visual/layout foi corrigido e está funcionando" (sem carregar no browser real e screenshot)

---

## 🔎 DESCONFIANÇA POR PADRÃO

**Antes de concluir sucesso, agente DEVE tentar provar que está ERRADO:**

1. **Como este teste pode estar me enganando?**
2. **Existe outra explicação para este resultado?**
3. **Eu mesmo provoquei o efeito que estou medindo?**
4. **Se fosse auditor externo, aceitaria esta evidência?**

**Se alguma pergunta não puder ser respondida, resultado DEVE ser INCONCLUSIVO.**

---

## 📝 TEMPLATE OBRIGATÓRIO DE RELATÓRIO

```markdown
# [Nome da Tarefa] - Relatório de Validação

**Data:** [ISO 8601]
**Agente:** [Nome]
**Resultado:** [COMPROVADO|FALHOU|INCONCLUSIVO]

## Evidência

### Preparação
- [ ] Estado inicial registrado
- [ ] Logs/métrica anterior capturada

### Disparo
- [ ] Mudança criada/enviada
- [ ] Confirmação de envio

### Espera
- [ ] Tempo suficiente para execução
- [ ] Sem intervenção manual

### Observação
- [ ] Método DIFERENTE de como foi disparado
- [ ] Comparação antes/depois
- [ ] Timestamps validados

## Dados Brutos
[Colar saída completa]

## Conclusão
[1-2 frases com evidência específica]

**Auditor Externo Aceitaria?** Sim / Não
```

---

## 📚 Referências Cruzadas

### Documentação Específica por Tópico

| Tópico | Arquivo | Escopo |
|--------|---------|--------|
| **Validação Geral** | Este arquivo (REGRAS-AGENTES-CENTRALIZADAS.md) | ✅ FONTE ÚNICA |
| **Princípios Gerais** | `docs/knowledge/agent-rules.md` | Fundamentos de diagnóstico |
| **Política de Imagens** | `docs/knowledge/image-policy.md` | Validação de imagens produtos |
| **Remote MCP** | `docs/AGENT-MCP-REMOTE.md` | Uso seguro de MCP remoto por agentes, sem publicar device code, device ID, e-mail completo ou tokens |

### Arquivos Deprecados

| Arquivo | Motivo | Ação |
|---------|--------|------|
| VALIDATION-POLICY.md | Conteúdo movido para este arquivo | ✅ Usar este arquivo em vez |
| SECRETS-SYNC-RULE.md | Conteúdo movido para Seção 6 | ✅ Usar este arquivo em vez |
| VALIDATION-RULES.md | Arquivo temporário da sessão anterior | ❌ Pode ser deletado |

---

## 🔄 Auditoria Semanal (Automática)

**Toda segunda-feira às 09:00 UTC:**

- [ ] Procurar novos arquivos `*-RULE.md` ou `*-POLICY.md` em raiz
- [ ] Confirmar que REGRAS-AGENTES-CENTRALIZADAS.md é FONTE ÚNICA
- [ ] Validar que docs/knowledge/* são apenas REFERÊNCIA
- [ ] Executar: `grep -r "NUNCA\|OBRIGATÓRIO\|PROIBIDO" . --include="*.md"` (detectar novas regras)
- [ ] Se encontrar novas regras: mover para este arquivo + criar entry em Referências Cruzadas

---

## 📞 Violações

**Se agente violar estas regras:**
1. Investigação e reorientação de agente
2. Registrar violação em CHANGELOG.md
3. Adicionar nova regra se necessário para evitar repetição

---

**Versão:** 2.0 (Consolidada)  
**Atualizado:** 2026-07-24  
**Próxima Revisão:** 2026-08-07  
**Status:** ✅ FONTE ÚNICA DE VERDADE
