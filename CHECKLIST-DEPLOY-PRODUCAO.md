# ✅ CHECKLIST: DEPLOY EM PRODUÇÃO

**Quando usar**: Depois que auditoria estiver 100% completa  
**Risco**: ALTO - isto vai para usuários reais  
**Validação**: Precisamos de 100% de confiança  

---

## 🚨 ANTES DE COLOCAR NO AR

### FASE 1: VALIDAÇÕES TÉCNICAS (Obrigatório)

#### 1.1 - Frete / Shipping ✅ ou ❌
```
[ ] Frete calcula corretamente
[ ] Múltiplas regiões testadas
[ ] Fallback funciona (se API cair)
[ ] Relatório de testes anexado
```

**Se algum falhar**: NÃO COLOCAR NO AR. Corrigir e revalidar.

#### 1.2 - Pedidos (Ponta-a-ponta) ✅ ou ❌
```
[ ] Carrinho funciona
[ ] Checkout carrega
[ ] Form valida
[ ] Pagamento processa
[ ] Email confirmação envia
[ ] Página obrigado carrega
[ ] Relatório de testes anexado
```

**Se algum falhar**: NÃO COLOCAR NO AR. Corrigir e revalidar.

#### 1.3 - Banco de Dados ✅ ou ❌
```
[ ] Tabelas existem
[ ] Dados persistem
[ ] Foreign keys funcionam
[ ] Backups rodam
[ ] Índices otimizados
[ ] Relatório de auditoria anexado
```

**Se algum falhar**: NÃO COLOCAR NO AR. Corrigir e revalidar.

#### 1.4 - Sync ao ERP (CRÍTICO) ✅ ou ❌
```
[ ] Token Olist renovado ✓✓✓
[ ] Teste de pedido chega no Olist ✓✓✓
[ ] Status retorna corretamente
[ ] Webhook funciona
[ ] Resync de pedidos antigos OK
[ ] Relatório de teste anexado
```

**Se algum falhar**: NÃO COLOCAR NO AR. CRÍTICO.

#### 1.5 - Status Flow ✅ ou ❌
```
[ ] Cliente vê status atualizado
[ ] Emails de notificação enviam
[ ] Tracking number mostra
[ ] Retorno de Olist é processado
[ ] Relatório de testes anexado
```

**Se algum falhar**: NÃO COLOCAR NO AR. Clientes confusos.

#### 1.6 - Pagamentos ✅ ou ❌
```
[ ] PIX: QR code gera e recebe
[ ] Credit Card: Pagar.me integrado
[ ] WhatsApp: Link funciona
[ ] Bank Transfer: Dados corretos
[ ] Failed payment: Error tratado
[ ] Refund: Implementado
[ ] Relatório de testes anexado
```

**Se algum falha**: NÃO COLOCAR NO AR. Perda de vendas.

#### 1.7 - Suporte ✅ ou ❌
```
[ ] Cliente vê histórico de pedidos
[ ] Rastreamento funciona
[ ] WhatsApp suporte linkado
[ ] Form suporte envia
[ ] Admin recebe requests
[ ] Invoice pode gerar
[ ] Relatório de testes anexado
```

**Se algum falha**: Clientes não conseguem suporte.

#### 1.8 - Segurança ✅ ou ❌
```
[ ] SQL Injection: não há
[ ] XSS: inputs sanitizados
[ ] CSRF tokens presentes
[ ] Senhas hasheadas
[ ] APIs autenticadas
[ ] Rate limiting ativo
[ ] HTTPS forçado
[ ] Auditoria de segurança anexada
```

**Se algum falha**: CRÍTICO. Não colocar no ar.

#### 1.9 - Performance ✅ ou ❌
```
[ ] Home carrega < 2s
[ ] Produto page < 2s
[ ] Checkout < 1s
[ ] Sem queries lentas
[ ] Índices do DB otimizados
[ ] Cache ativo
[ ] Relatório de performance anexado
```

**Se algum falha**: Usuários abandoning cart.

#### 1.10 - Analytics ✅ ou ❌
```
[ ] GA4 com ID REAL (não test)
[ ] Purchase event tracking funciona
[ ] Conversions sendo registradas
[ ] Google Ads pixels ativos
[ ] Relatório verificado
```

**Se algum falha**: Não sabe quem está convertendo.

---

### FASE 2: PREPARAÇÃO DE PRODUÇÃO

#### 2.1 - Credenciais/Secrets
```
[ ] Todos os .env secrets estão REAIS (não test)
[ ] Nenhum token de teste em produção
[ ] GOOGLE_OAUTH_CLIENT_ID = real
[ ] OLIST_ACCESS_TOKEN = válido
[ ] GA_MEASUREMENT_ID = real (não G-TEST1234567)
[ ] Senhas DB são seguras
```

#### 2.2 - Configuração
```
[ ] Domain apontando corretamente
[ ] SSL/TLS ativo
[ ] Email SMTP configurado
[ ] Backups agendados
[ ] Logs rotacionando
[ ] Rate limiting configurado
```

#### 2.3 - Monitoramento
```
[ ] Sentry/alerting ativo
[ ] Health checks funcionando
[ ] Dashboard de monitoring pronto
[ ] Alertas de falha configurados
[ ] On-call contactos definidos
```

#### 2.4 - Documentação
```
[ ] README atualizado
[ ] API docs completos
[ ] Runbook de troubleshooting
[ ] Escalation procedures
[ ] Backup/restore procedures
```

---

### FASE 3: VALIDAÇÃO FINAL

#### 3.1 - Teste de Cenários Reais
```
Simule 5 pedidos completos:

Pedido 1: PIX + São Paulo
[ ] Pedido criado
[ ] Olist recebeu
[ ] Email enviado
[ ] Status atualizou

Pedido 2: CC + Rio de Janeiro
[ ] Mesmo fluxo

Pedido 3: WhatsApp + Outro estado
[ ] Mesmo fluxo

Pedido 4: Fallback frete (se disponível)
[ ] Mesma validação

Pedido 5: Return/refund flow
[ ] Pedido pode ser revertido
```

#### 3.2 - Teste de Falhas
```
Simule cenários de erro:

Frete API cai:
[ ] Fallback funciona?
[ ] Checkout não quebra?

Pagamento falha:
[ ] Erro tratado?
[ ] Cliente notificado?

Olist API cai:
[ ] Pedido salvo localmente?
[ ] Retry programado?

Email SMTP cai:
[ ] Logs registram?
[ ] Admin alertado?
```

---

### FASE 4: APROVAÇÃO FINAL

#### 4.1 - Sign-off de Responsáveis
```
[ ] Desenvolvedor: "Código pronto"
[ ] QA: "Testes passam"
[ ] Admin: "Servers pronto"
[ ] Negócio: "Aprovado para ir"
```

#### 4.2 - Backup Plano
```
[ ] Backup do código anterior pronto
[ ] Database backup feito
[ ] Plano de rollback documentado
[ ] Recovery time objetivo < 1h
```

#### 4.3 - Comunicação
```
[ ] Clientes avisados (se maintenance)
[ ] Suporte treinado (novo fluxo)
[ ] Agentes testaram (tudo funciona)
[ ] Help desk tem runbook
```

---

## 🚀 DEPLOY STEP-BY-STEP

### Quando TUDO acima estiver ✅

1. **Backup** (5 min)
   ```bash
   # Database backup
   # Code backup
   # Verificar que backed up
   ```

2. **Deploy Code** (5-10 min)
   ```bash
   git push origin main
   # VM Oracle sync automático em 30min
   # Ou manual: ssh ... && git pull
   ```

3. **Verificar Saúde** (5 min)
   ```bash
   # Health check endpoint
   # Home page carrega
   # API responde
   ```

4. **Teste Smoke** (10 min)
   ```
   [ ] Home page carrega
   [ ] Produto page carrega
   [ ] Checkout inicia
   [ ] Pagamento funciona (fake payment)
   ```

5. **Monitorar** (próximas 24h)
   ```
   [ ] Logs limpos
   [ ] Sem errors
   [ ] Performance normal
   [ ] Usuários usando normalmente
   [ ] Conversões rastreadas
   ```

---

## 🚨 CENÁRIOS DE ROLLBACK

**Se algo der errado**:

```
1. Detectar erro (< 5 min)
2. Marcar como incident
3. Parar vendas (temporário)
4. Rollback (5-10 min)
5. Verificar funciona
6. Post-mortem
7. Fix e redeploy (24h depois)
```

**Rollback automático**:
```bash
git revert HEAD
git push origin main
# Volta para versão anterior
```

---

## 📊 DEFINIÇÃO DE SUCESSO

Após 48h de produção:

```
✅ Vendas funcionando
✅ Pedidos chegando ao ERP
✅ Clientes recebendo status
✅ Zero refunds por falha técnica
✅ Performance normal
✅ Sem crashes
✅ Suporte consegue ajudar
```

Se tudo isto for verdade: **SUCESSO** 🎉

---

## 📋 CHECKLIST FINAL (30 min antes de ir ao ar)

```
SEGURANÇA
[ ] Nenhum `.env.example` em produção
[ ] Nenhum console.log com dados sensíveis
[ ] SSL ativo
[ ] CORS configurado correto
[ ] Auth funcionando

DADOS
[ ] Database migrado
[ ] Backup anterior acessível
[ ] Foreign keys OK
[ ] Índices criados

OPERAÇÕES
[ ] Logs ligados
[ ] Alerting ativo
[ ] Monitoramento OK
[ ] Backup automatizado
[ ] Recovery plano

NEGÓCIO
[ ] Analytics pronto
[ ] Conversions rastreadas
[ ] Support ready
[ ] Product marketing OK
```

---

## 📞 CONTATO EM PRODUÇÃO

Se problema crítico surge:
1. Alerting notifica (automático)
2. On-call ativado
3. Incident investigation
4. Fix ou rollback em < 30 min
5. Post-mortem em 24h

---

## ✨ RESULTADO

**Depois de tudo isto**:
- 🟢 Site está em produção
- 🟢 Usuários podem comprar
- 🟢 Pedidos chegam no ERP
- 🟢 Negócio pode começar a ganhar dinheiro
- 🟢 Agentes podem monitorar

---

**Este checklist deve ser 100% completo antes de ir ao ar.**

**Nenhum "quase" - tudo deve estar PRONTO.**

🚀 **QUANDO TUDO ESTIVER PRONTO: GO LIVE** 🚀

