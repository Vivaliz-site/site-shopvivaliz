# 📋 AUDITORIA DE REGRAS - 2026-07-24

**Status:** ⚠️ FRAGMENTADO - CONSOLIDAÇÃO NECESSÁRIA  
**Data:** 2026-07-24  
**Responsável:** Claude Code (Auditoria Autônoma)

---

## 🔍 Arquivos de Regras Encontrados

| Arquivo | Linhas | Escopo | Status | Prioridade |
|---------|--------|--------|--------|-----------|
| **VALIDATION-POLICY.md** | 292 | Validação (UI, deploy, APIs, pagamentos, emails) | ✅ ATIVO | 🔴 CRÍTICO |
| **SECRETS-SYNC-RULE.md** | 214 | Sincronização de secrets (3 ambientes) | ✅ ATIVO | 🔴 CRÍTICO |
| **docs/knowledge/agent-rules.md** | 54 | Princípios gerais de diagnóstico | ✅ ATIVO | 🟡 MÉDIO |
| **docs/knowledge/image-policy.md** | 28 | Política específica de imagens de produtos | ✅ ATIVO | 🟡 MÉDIO |
| **CLAUDE.md** | 361 | Guia de sistema (NÃO é arquivo de regras) | ℹ️ REFERÊNCIA | ⚪ NÃO APLICA |
| **CLAUDE-AUTONOMO.md** | 295 | Arquitetura autônoma (NÃO é arquivo de regras) | ℹ️ REFERÊNCIA | ⚪ NÃO APLICA |

**TOTAL**: 4 arquivos de REGRAS ATIVAS + 2 arquivos de REFERÊNCIA

---

## ✅ Mapeamento de Regras Críticas

### VALIDATION-POLICY.md (292 linhas)

#### Princípios Fundamentais (4 Invioláveis)
1. ✅ **NUNCA declare sucesso sem evidência INDEPENDENTE**
   - Proibido: "deve ter funcionado", "provavelmente OK"
   - Obrigatório: evidência verificável com logs/dados

2. ✅ **NUNCA considere ação MANUAL como prova de AUTOMAÇÃO**
   - Proibido: testes manuais durante teste de automação
   - Separação obrigatória: Preparação → Disparo → Espera → Observação

3. ✅ **QUALQUER ERRO INTERROMPE ROTINA**
   - Obrigatório: `set -Eeuo pipefail` em todo script
   - Sem supressão de erros (`|| true`) sem justificativa

4. ✅ **RESULTADO SÓ PODE SER: COMPROVADO, FALHOU, INCONCLUSIVO**
   - Proibido: "parece funcionar", "acho que"
   - Obrigatório: STATUS claro com evidência

#### Regras Específicas por Tipo
- ✅ Deploy de código (Git SHA, HTTP 200, logs)
- ✅ Webhook & callbacks (POST confirmado, log servidor, persistência)
- ✅ API & integração (HTTP 2xx, JSON com chaves, idempotência)
- ✅ Banco de dados (INSERT com exit 0, SELECT confirmação)
- ✅ Pagamento (Webhook, signature HMAC, status order, email, ERP)
- ✅ E-mail (SMTP connection, 250 accepted, entrega INBOX)
- ✅ ERP & Olist & Shopee (auth 401, sync 200, idempotência)
- ✅ **UI/UX/Frontend (NEW)**: Screenshot REAL de navegador REAL, sem simulação

#### Red Flags (Proibições Automáticas)
- ❌ Erro detectado em saída (`error:`, `fatal:`, `rejected:`, `timeout`)
- ❌ Exit code ≠ 0
- ❌ Continuação após erro (`|| echo "OK"`)
- ❌ Supressão de erros (`2>/dev/null` sem justificativa)
- ❌ Intervenção manual durante teste de automação
- ❌ Sem logs relevantes, sem timestamps
- ❌ Inferência ("deve ter funcionado")

#### Template Obrigatório de Relatório
- ✅ Preparação (estado inicial registrado)
- ✅ Disparo (mudança criada/enviada)
- ✅ Espera (sem intervenção)
- ✅ Observação (método diferente de disparo)
- ✅ Dados Brutos (saída completa)
- ✅ Conclusão (evidência específica)
- ✅ Pergunta-teste: "Auditor Externo Aceitaria?"

---

### SECRETS-SYNC-RULE.md (214 linhas)

#### Regra Obrigatória
1. ✅ **Sincronizar secrets em 3 ambientes sempre**
   - Local: `C:\Users\FRED\site-shopvivaliz\.env`
   - VM: `ubuntu@137.131.156.17:/home/ubuntu/site-shopvivaliz/.env`
   - GitHub: `Settings > Secrets and variables > Actions`

#### Quando Aplica
- ✅ Adicionar novo secret
- ✅ Atualizar valor (rotação)
- ✅ Remover secret deprecado
- ✅ Renovar token expirado

#### Checklist de Sincronização
1. Editar local
2. Copiar para VM (scp)
3. Atualizar GitHub (gh secret set)
4. Validar em 3 locais (grep, ssh, gh secret list)
5. Commitar mudança

#### Matriz de Sincronização
| Tipo | Local | VM | GitHub | Notas |
|------|-------|-----|--------|-------|
| Database | ✅ | ✅ | ❌ | Risco em GitHub |
| Email/SMTP | ✅ | ✅ | ✅ | Seguro |
| APIs IA | ✅ | ✅ | ✅ | Sincronizar sempre |
| ERP/Commerce | ✅ | ✅ | ✅ | Sincronizar sempre |
| Deploy/FTP | ✅ | ❌ | ✅ | Local + GitHub apenas |
| CloudFlare | ✅ | ❌ | ✅ | Local + GitHub apenas |

---

### docs/knowledge/agent-rules.md (54 linhas)

#### Fonte de Conhecimento
- ✅ Usar `/docs/knowledge/` como base inicial
- ✅ Confirmar no código/workflow/log quando documentação insuficiente
- ✅ NUNCA assumir resposta sem evidência
- ✅ Informar claramente quando evidência incompleta/ambígua/desatualizada

#### Diagnóstico
- ✅ Identificar erro antes de sugerir solução
- ✅ Registrar: HTTP method, URL, status, corpo, etapa afetada
- ✅ NUNCA tratar 404, 405, 500, CORS, DNS como mesmo problema
- ✅ NUNCA declarar produção/deploy/banco/preço/imagem/integração corretos sem teste

#### Validação Squad Chat
- ✅ Health válido SOMENTE quando: `ok=true`, `endpoint=squad-chat`, campo `providers` presente
- ⚠️ Campo `configured` NÃO prova credencial aceita pelo provider

#### Credenciais e Segurança
- ✅ Usar variáveis de ambiente ou GitHub Secrets
- ✅ GitHub Secrets são write-only (nunca recuperar em texto)
- ✅ NUNCA hardcodar/registrar/exibir: senhas, tokens, chaves API, dados bancários
- ✅ NUNCA contornar: CORS, autenticação, controles de acesso

#### Catálogo e Integrações
- ✅ NUNCA inventar: preço, estoque, frete, imagem, disponibilidade
- ✅ NUNCA alterar campos comerciais sem evidência da fonte oficial
- ✅ Ignorar/sinalizar produtos sem estoque conforme regra do canal
- ✅ Vincular imagens por identificador confiável (SKU ou ID origem)
- ✅ Distinguir: falha interface vs falha sincronização vs ausência dados

#### Atualizações
- ✅ Produzir atualizações cumulativas (permitir pular versões intermediárias)
- ✅ Incluir automaticamente: SQLs, migrations, reparos de vínculo
- ✅ Migrations idempotentes e registrar execução
- ✅ Executar em mesma atualização: preflight, backup, copy, migrations, reparos, testes
- ✅ NUNCA exigir abertura manual de links para completar instalação
- ✅ Fazer merge APENAS quando mudanças consistentes e validadas

#### Autonomia
- ✅ Tomar decisões autônomas dentro escopo autorizado
- ✅ Interromper ações destrutivas, irreversíveis, sem evidência suficiente
- ⚠️ Autonomia ≠ substituição de validação

---

### docs/knowledge/image-policy.md (28 linhas)

#### Regra Principal
- ✅ NUNCA exibir placeholders, ilustrações genéricas, ou logo Vivaliz

#### Categorias
- ✅ Cada categoria = imagem REAL de produto da própria categoria
- ✅ Priorizar produtos com estoque, preço, slug válidos
- ✅ Categorias sem imagem REAL = ocultas até correção
- ✅ Vínculo por categoria normalizada + SKU/ID confiável

#### Produtos
- ✅ `image_url` vazia/placeholder/logo = INVÁLIDA
- ✅ Falhas carregamento = registrar (não substituir silenciosamente)
- ✅ Imagens importadas Olist/Tiny = manter vínculo com SKU correto

#### Validação
- ✅ Endpoint: `/api/catalog/category-images.php`
- ✅ Health: `/api/catalog/image-health.php`
- ✅ Quality gate: `php scripts/quality/validate-category-images.php`

#### Evidência
- ✅ Correção concluída APENAS quando:
  - Categoria/produto renderiza imagem REAL
  - Imagem ligada ao item correto
  - Health check confirma cobertura válida

---

## 🔴 CONFLITOS DETECTADOS

### Análise de Redundâncias

| Arquivo A | Arquivo B | Redundância | Severidade | Recomendação |
|-----------|-----------|-------------|-----------|--------------|
| VALIDATION-POLICY | SECRETS-SYNC-RULE | Ambos tratam "validação/regras críticas" | 🟡 MÉDIA | **CONSOLIDAR** em VALIDATION-POLICY |
| agent-rules | image-policy | Não - escopo diferente | ⚪ NONE | Manter separados |
| VALIDATION-POLICY | agent-rules | Slight overlap em "NUNCA declarar sucesso" | ⚪ NONE | Referência cruzada é OK |

### Conflitos Diretos

**✅ NENHUM CONFLITO DIRETO ENCONTRADO**

- ❌ VALIDATION-POLICY diz validar com curl/grep
- ✅ agent-rules concorda: "confirmar no código/workflow/log"
- ✅ image-policy não conflita (específico de imagens)
- ✅ SECRETS-SYNC-RULE não conflita (específico de secrets)

**✅ TODAS AS REGRAS SÃO COMPATÍVEIS**

---

## 🎯 RECOMENDAÇÕES

### 1. CONSOLIDAÇÃO (CRÍTICO)
```
MOVER: SECRETS-SYNC-RULE.md → VALIDATION-POLICY.md (seção nova)
RENOMEAR: VALIDATION-POLICY.md → REGRAS-AGENTES-CENTRALIZADAS.md

Resultado:
- ✅ 1 arquivo central com TODAS as regras críticas
- ✅ agent-rules.md mantém-se como referência de princípios
- ✅ image-policy.md mantém-se como referência específica
```

### 2. ESTRUTURA DO ARQUIVO CENTRALIZADO

```markdown
# REGRAS-AGENTES-CENTRALIZADAS.md

## 1. Princípios Fundamentais (4 Invioláveis)
   [conteúdo de VALIDATION-POLICY]

## 2. Matriz de Evidências por Tipo
   [conteúdo de VALIDATION-POLICY]

## 3. Red Flags & Proibições
   [conteúdo de VALIDATION-POLICY]

## 4. Sincronização de Secrets (3 Ambientes)
   [conteúdo de SECRETS-SYNC-RULE]

## 5. Referências Cruzadas
   - docs/knowledge/agent-rules.md (princípios gerais)
   - docs/knowledge/image-policy.md (política de imagens)
```

### 3. VERIFICAÇÃO SEMANAL

Adicionar regra ao REGRAS-AGENTES-CENTRALIZADAS.md:

```markdown
## Auditoria Semanal (Toda segunda-feira)

- [ ] Verificar se há novos arquivos *-RULE.md ou *-POLICY.md
- [ ] Confirmar que REGRAS-AGENTES-CENTRALIZADAS.md é FONTE ÚNICA
- [ ] Validar que agent-rules.md + image-policy.md são apenas REFERÊNCIA
- [ ] Executar: grep -r "NUNCA\|OBRIGATÓRIO\|PROIBIDO" . (detectar novas regras)
```

---

## 📊 Resumo Executivo

| Métrica | Valor | Status |
|---------|-------|--------|
| **Arquivos de regras** | 4 | ✅ Descobertos |
| **Conflitos diretos** | 0 | ✅ NONE |
| **Redundâncias** | 1 (SECRETS em VALIDATION) | ⚠️ CONSOLIDAR |
| **Cobertura de tópicos** | 100% | ✅ Completa |
| **Fonte única de verdade** | NÃO (2 arquivos) | 🔴 CORRIGIR |

---

## 🚀 Ação Recomendada

**IMEDIATO (hoje):**
1. ✅ Consolidar SECRETS-SYNC-RULE.md em VALIDATION-POLICY.md
2. ✅ Renomear para REGRAS-AGENTES-CENTRALIZADAS.md
3. ✅ Atualizar referências em CLAUDE.md e CLAUDE-AUTONOMO.md
4. ✅ Sincronizar em VM e GitHub

**CURTO PRAZO (semana):**
- Adicionar regra de auditoria semanal
- Atualizar docs/knowledge/agent-rules.md com link de referência cruzada

---

**Auditoria**: ✅ COMPLETA  
**Ação Necessária**: 🔴 CONSOLIDAÇÃO IMEDIATA  
**Próxima Revisão**: 2026-07-31
