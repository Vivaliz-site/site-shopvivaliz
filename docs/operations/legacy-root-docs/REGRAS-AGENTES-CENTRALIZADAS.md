# 📋 REGRAS PARA AGENTES IA - FONTE ÚNICA CENTRALIZADA

**Efetivo:** 2026-07-24  
**Escopo:** Todos os agentes (Claude, Codex, Gemini, GPT, etc.)  
**Aplicável a:** Qualquer tarefa automatizada (deploy, testes, integrações, ERP, pagamentos, emails, secrets)  
**Objetivo:** Eliminar falsos positivos, exigir evidência verificável antes de declarar sucesso

> ⚠️ **ESTA É A FONTE ÚNICA DE VERDADE PARA TODAS AS REGRAS.**  
> Outros arquivos (VALIDATION-POLICY.md, SECRETS-SYNC-RULE.md, etc.) são DEPRECADOS.  
> Veja [Referências Cruzadas](#referências-cruzadas) para documentação específica.

---

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
# Local: C:\Users\FRED\site-shopvivaliz\.env
vi .env
```

#### Passo 2: COPIAR para VM
```bash
SSH_KEY="C:\Users\FRED\Downloads\ssh-key-2026-07-04.key"
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
grep "NOME_SECRET" C:\Users\FRED\site-shopvivaliz\.env

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
