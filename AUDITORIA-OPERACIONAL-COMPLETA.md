# 🔍 AUDITORIA OPERACIONAL COMPLETA - ShopVivaliz

**Data**: 2026-07-12  
**Escopo**: Teste real de TODA a operação de e-commerce  
**Status**: 🔄 EM EXECUÇÃO

---

## 📋 ROTEIRO DE AUDITORIA (7 FASES)

### FASE 1: 📦 FRETE / SHIPPING
**Responsável**: Agente de Auditoria  
**O que testar**:
- [ ] Integração Melhor Envio funcionando
- [ ] Cálculo de frete correto
- [ ] Múltiplas opções de frete disponíveis
- [ ] Fallback se API cair
- [ ] Validação de CEP
- [ ] Peso/dimensões sendo usados
- [ ] Free shipping rules funcionando

**Se encontrar problema**:
```
1. Documente exatamente o que falha
2. Encontre o arquivo e linha do problema
3. CORRIJA o código
4. TESTE novamente
5. Commite a correção
```

---

### FASE 2: 🛒 CRIAÇÃO DE PEDIDO
**Responsável**: Agente de Auditoria  
**O que testar**:
- [ ] Adicionar produto ao carrinho
- [ ] Validação de estoque
- [ ] Atualizar quantidade
- [ ] Remover item
- [ ] Carrinho persiste no refresh
- [ ] Form de checkout carrega
- [ ] Validação de campos
- [ ] Endereço válido
- [ ] Pagamento seleciona
- [ ] Pedido é criado

**Se encontrar problema**:
```
Faça o mesmo: Document → Find → Fix → Test → Commit
```

---

### FASE 3: 💾 PERSISTÊNCIA DE DADOS
**Responsável**: Agente de Auditoria  
**O que testar**:
- [ ] Pedido criado no banco de dados
- [ ] Todos os campos salvos (customer, items, total)
- [ ] Itens do pedido linkados
- [ ] Dados de pagamento salvos
- [ ] Dados de frete salvos
- [ ] Relacionamentos no DB funcionando
- [ ] Foreign keys intactos

**Se encontrar problema**:
```
Pode ser:
1. Schema missing - criar migration
2. Query errada - corrigir SQL
3. Valor não sendo passado - corrigir código
```

---

### FASE 4: 🔄 ENVIO AO ERP
**Responsável**: Agente de Auditoria  
**O que testar**:
- [ ] Subscriber order-created.ts (que criamos) está ativo
- [ ] Pedido é enviado para Olist/Tiny
- [ ] ID do Olist volta e é salvo
- [ ] Se falhar API: há retry?
- [ ] Se falhar credencial: há log?
- [ ] Admin pode ver status de sync

**Se encontrar problema**:
```
Problemas comuns:
1. OLIST_ACCESS_TOKEN não configurado → configurar .env
2. Subscriber não roda → verificar arquivo e logs
3. API retorna erro → debugar format de dados
4. Sem retry → implementar retry logic
```

---

### FASE 5: 📊 FLUXO DE STATUS
**Responsável**: Agente de Auditoria  
**O que testar**:
- [ ] Novo pedido tem status "pending"
- [ ] Pagamento recebido: status muda
- [ ] Webhook do Olist atualiza status
- [ ] Cliente vê atualizações
- [ ] Admin vê atualizações
- [ ] Emails de notificação enviados
- [ ] Tracking number é mostrado

**Se encontrar problema**:
```
Problemas comuns:
1. Status não atualiza → webhook não recebe
2. Webhook recebe mas BD não atualiza → query errada
3. Cliente não vê → UI não carrega status novo
4. Email não envia → SMTP config ou trigger missing
```

---

### FASE 6: 🆘 FLUXOS DE SUPORTE
**Responsável**: Agente de Auditoria  
**O que testar**:
- [ ] Cliente vê histórico de pedidos
- [ ] Pode rastrear pedido
- [ ] WhatsApp funciona
- [ ] Form de suporte envia
- [ ] Admin recebe requests
- [ ] Can gerar invoice/recibo
- [ ] Returns/refunds processam

**Se encontrar problema**:
```
Problemas comuns:
1. Feature não existe → implementar
2. Feature quebrada → debugar e corrigir
3. Admin não recebe → verificar webhook/email
```

---

### FASE 7: 🔧 ISSUES & FIXES

**Para CADA issue encontrado**:

```markdown
## ISSUE #N: [Título descritivo]

**Localização**: `/arquivo/path.php:linha_123`  
**Severidade**: CRITICAL | HIGH | MEDIUM

**Comportamento atual**: [O que acontece agora]

**Comportamento esperado**: [O que deveria acontecer]

**Causa raiz**: [Por que está quebrado]

**Solução**:
```
[Código ou mudança específica]
```

**Teste**:
```
[Exatos passos para verificar que foi corrigido]
```

**Commit**:
```
fix: [descrição clara do que foi fixado]
```
```

---

## ⚡ PROTOCOLO DE CORREÇÃO AUTOMÁTICA

**Quando encontrar um problema**:

1. **INVESTIGATE** (5 min)
   - Confirme o problema
   - Encontre exatamente onde no código
   - Entenda a causa raiz

2. **FIX** (5-30 min)
   - Corrija o código
   - Não faça refactoring extra
   - Apenas resolvem o problema

3. **TEST** (5 min)
   - Teste que foi corrigido
   - Verifique não quebrou nada
   - Simule cenários de erro

4. **COMMIT** (2 min)
   - Git add + commit
   - Mensagem clara do que foi fixado
   - Commit automático ao repo

5. **CONTINUE** (0 min)
   - Próximo problema
   - Sem esperar por nada

---

## 🚨 PROBLEMAS CRÍTICOS CONHECIDOS

### 1. Frete não funciona (FASE 1)
**Causa**: Possível API key errada ou integração incompleta  
**Impacto**: Checkout não completa  
**Ação**: Verificar Melhor Envio integration

### 2. Pedido não chega no ERP (FASE 4)
**Causa**: Subscriber order-created.ts criado mas não tem OLIST_ACCESS_TOKEN  
**Impacto**: Fornecedor não recebe pedido  
**Ação**: Configurar token e ativar subscriber

### 3. Status não atualiza (FASE 5)
**Causa**: Webhook do Olist não recebe update ou não processa  
**Impacto**: Cliente não vê progresso  
**Ação**: Verificar webhook e query de update

---

## ✅ DEFINIÇÃO DE "PRONTO PARA PRODUÇÃO"

**Pode ir ao ar quando**:
- ✅ Frete calcula corretamente
- ✅ Pedido criado no banco
- ✅ Pedido enviado ao ERP
- ✅ Status retorna do ERP
- ✅ Cliente vê tudo correto
- ✅ Suporte consegue ajudar
- ✅ Zero erros nos logs

**NÃO pode ir se**:
- ❌ Frete quebrado
- ❌ Pedido não chega no ERP
- ❌ Dados não persistem
- ❌ Status não atualiza
- ❌ Cliente não vê pedido

---

## 📊 PROGRESSO

```
FASE 1: Frete              [ ] Iniciado [ ] OK [ ] ISSUES ENCONTRADOS
FASE 2: Criação de Pedido  [ ] Iniciado [ ] OK [ ] ISSUES ENCONTRADOS
FASE 3: Persistência       [ ] Iniciado [ ] OK [ ] ISSUES ENCONTRADOS
FASE 4: Sync ERP           [ ] Iniciado [ ] OK [ ] ISSUES ENCONTRADOS
FASE 5: Status             [ ] Iniciado [ ] OK [ ] ISSUES ENCONTRADOS
FASE 6: Suporte            [ ] Iniciado [ ] OK [ ] ISSUES ENCONTRADOS
FASE 7: Compile            [ ] Iniciado [ ] OK [ ] RELATÓRIO FINAL
```

---

## 📞 CONTATO RÁPIDO

Se tiver BLOQUEADOR crítico:
1. Log com detalhe no BLOQUEADOR.md
2. Agente de auditoria principal pode pedir help
3. Agents trabalham em paralelo, não serializado

---

## 🎯 META

**Nenhum pedido deve ser perdido.**

Toda a cadeia (frete → pedido → BD → ERP → status → suporte) deve funcionar sem falhas.

Se falhar em qualquer ponto → FIXAR IMEDIATAMENTE.

---

**Auditoria iniciada**: 2026-07-12 (agora)  
**Prazo**: 48h para ter relatório final e todos os issues corrigidos

🚀 **COMEÇAR AUDITORIA!** 🚀

