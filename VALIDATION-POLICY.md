# 📋 POLÍTICA GERAL DE VALIDAÇÃO PARA AGENTES IA

**Efetivo:** 2026-07-15  
**Escopo:** Todos os agentes (Claude, Codex, Gemini, GPT, etc.)  
**Aplicável a:** Qualquer tarefa automatizada (deploy, testes, integrações, ERP, pagamentos, e-mails)  
**Objetivo:** Eliminar falsos positivos, exigir evidência verificável antes de declarar sucesso

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

---

## 🛑 PROIBIÇÃO DE INFERÊNCIA

**Nunca conclua que algo ocorreu porque "deveria ter ocorrido". VERIFIQUE.**

❌ "O webhook foi enviado com sucesso" (sem ver log servidor)
❌ "A mudança deve ter sincronizado" (sem verificar SHA na VM)
❌ "Nenhum erro visto, então funcionou" (ausência ≠ sucesso)
❌ "O agente deve ter executado" (onde está o log?)
❌ "API respondeu 200, deve estar funcionando" (validar corpo também)

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

**Versão:** 1.0  
**Próxima revisão:** 2026-08-15  
**Violações:** Investigação e reorientação de agente
