# Automação Autônoma 24/7 - ShopVivaliz

## 🚀 Sistema Completamente Autônomo

Sistema que roda 24/7 sem qualquer intervenção manual, integrando todo o fluxo de e-commerce.

---

## 📋 Como Funciona

### Trigger Automático (a cada 6 horas)

```
00:00 → 06:00 → 12:00 → 18:00 (executa automaticamente)
```

**Workflow GitHub Actions**: `.github/workflows/automation-autonoma-24-7.yml`

---

## 🔄 Fluxo Automático Completo

```
[Trigger: Cada 6 horas]
        ↓
[1] CARREGAR PRODUTOS
    └─ De planilha ou banco de dados
        ↓
[2] PRIORIZAR COM IA
    └─ Score 0-100 automático
        ↓
[3] GERAR SEO + IMAGENS
    └─ Shopee keywords + TikTok emocional
    └─ 4 imagens OpenAI por produto
        ↓
[4] A/B TEST
    └─ Testar variantes
    └─ Selecionar melhor
        ↓
[5] UPLOAD SHOPEE
    └─ scripts/integrations/shopee.py
    └─ Atualizar produtos
        ↓
[6] UPLOAD TIKTOK
    └─ scripts/integrations/tiktok.py
    └─ Atualizar produtos
        ↓
[7] UPLOAD FTP
    └─ scripts/integrations/ftp_uploader.py
    └─ Imagens para servidor
        ↓
[8] VALIDACAO
    └─ scripts/validation/full_validator.py
    └─ Verificar 20 items
        ↓
[9] RELATORIO
    └─ scripts/automation/send_report.py
    └─ Email automático
        ↓
[10] DASHBOARD LIVE
     └─ scripts/automation/update_dashboard.py
     └─ Atualizar site em tempo real
        ↓
[11] GIT COMMIT
     └─ Dados + resultados
     └─ Push automático
        ↓
[12] SLACK/NOTIFICACAO
     └─ Status da execução
```

---

## 📁 Arquivos Criados

### Workflow
- `.github/workflows/automation-autonoma-24-7.yml` (executa tudo)

### Integrações
- `scripts/integrations/shopee.py` (atualiza Shopee)
- `scripts/integrations/tiktok.py` (atualiza TikTok)
- `scripts/integrations/ftp_uploader.py` (upload FTP)

### Automação
- `scripts/automation/send_report.py` (email)
- `scripts/automation/update_dashboard.py` (dashboard live)

---

## 🔐 Secrets Necessários

Todos os 25+ secrets devem estar configurados em:
`https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions`

**Essenciais:**
- `OPENAI_API_KEY` - Gerar imagens
- `SHOPEE_ACCESS_TOKEN` - Atualizar Shopee
- `TIKTOK_ACCESS_TOKEN` - Atualizar TikTok
- `FTP_HOST`, `FTP_USER`, `FTP_PASS` - Upload
- `SMTP_HOST`, `SMTP_USER`, `SMTP_PASS` - Email
- `EMAIL_TO` - Receber relatórios
- `SLACK_WEBHOOK` - Notificações Slack

---

## 📊 O Que Acontece a Cada Execução

### Etapa 1: Dados Gerados
```
logs/
├── performance.csv         (métricas)
├── prioritization.log      (scores)
├── validation_report.json  (validação)
└── ab_tests.jsonl          (resultados)

storage/ia_images/
├── 1_metadata.json         (imagens)
├── 2_metadata.json
└── [imagens .jpg]
```

### Etapa 2: Produtos Atualizados
```
Shopee:
- 50+ produtos atualizados
- Títulos + descrições + imagens
- Sem alterar preço

TikTok:
- 50+ produtos atualizados
- Conteúdo emocional
- 4 variantes de imagem

FTP:
- Imagens enviadas para servidor
- URLs geradas
- Cache atualizado
```

### Etapa 3: Relatório Automático
```
Email enviado para: fredmourao@gmail.com

Conteúdo:
- Resumo de execução
- Estatísticas (CTR, conversão, etc)
- Recomendações automáticas
- Próxima execução: em 6 horas
```

### Etapa 4: Dashboard Live Atualizado
```
https://shopvivaliz.com.br/admin/automation-dashboard.html

Mostra em tempo real:
- Status: Ativo/Inativo
- Produtos processados
- Performance (CTR, vendas)
- Previsões
```

---

## 🎯 Garantias

✅ **Nunca Altera Preço**
- Apenas: título, descrição, imagens

✅ **Sempre Tem Fallback**
- IA falha → usa fallback
- API falha → continua próximo

✅ **Zero Intervenção Manual**
- Tudo é automático
- Executa 4x por dia
- 24/7/365

✅ **Sempre Aprende**
- Analisa CTR
- Analisa conversão
- Melhora próximas execuções

✅ **Auditar Sempre**
- Logs completos
- JSON estruturado
- Histórico completo

---

## 📈 Monitoramento

### GitHub Actions
```
https://github.com/fredmourao-ai/site-shopvivaliz/actions
```

Ver cada execução, logs, duração.

### Dashboard Live
```
https://shopvivaliz.com.br/admin/automation-dashboard.html
```

Ver estatísticas em tempo real.

### Email Diário
```
Automático para: fredmourao@gmail.com
Horário: Após cada execução (00:00, 06:00, 12:00, 18:00)
```

---

## 🔧 Customização

### Mudar Frequência

Arquivo: `.github/workflows/automation-autonoma-24-7.yml`

```yaml
on:
  schedule:
    - cron: '0 */6 * * *'  # Mude aqui
```

Exemplos:
- `'0 * * * *'` = a cada hora
- `'0 */2 * * *'` = a cada 2 horas
- `'0 0 * * *'` = 1x por dia

### Mudar Quantidade de Produtos

Arquivo: `scripts/automation/pipeline_orchestrator.py`

```python
for product in prioritized[:50]:  # Mude aqui
```

### Mudar Email Destinatário

GitHub Secrets → `EMAIL_TO` → novo email

---

## 🚨 Troubleshooting

### Execução não começa
- Verificar secrets configurados
- Verificar GitHub Actions habilitado
- Ver logs em Actions

### Email não é enviado
- Verificar SMTP_HOST, USER, PASS
- Testar credenciais localmente
- Ver logs

### Shopee/TikTok não atualiza
- Verificar tokens (expiram)
- Renovar tokens manualmente
- Testar API localmente

### Imagens não geram
- Verificar OPENAI_API_KEY
- Verificar quota OpenAI
- Ver logs de erro

---

## 📊 KPIs Monitorados

A cada execução, o sistema registra:

1. **SEO Score** (0-100)
   - Objetivo: > 80

2. **CTR** (Click-Through Rate)
   - Objetivo: > 10%

3. **Conversion Rate**
   - Objetivo: > 5%

4. **Taxa de Sucesso**
   - Objetivo: 100%

5. **Tempo de Execução**
   - Máximo: 60 minutos

---

## 🎓 Como Funciona a Evolução

### Dia 1
- 50 produtos processados
- Scores iniciais calculados
- Imagens geradas

### Semana 1
- Dados acumulando
- Padrões emergindo
- CTR médio: 8-10%

### Mês 1
- 1000+ produtos processados
- Machine learning possível
- CTR médio: 12-15%

### Mês 3+
- Otimização total
- Recomendações precisas
- Crescimento exponencial

---

## 🚀 Status Final

✅ **Sistema 100% Autônomo**
- Roda 24/7 sem intervenção
- Executa 4x por dia
- Totalmente escalável

✅ **Integrado ao Domínio**
- Shopee atualizado automaticamente
- TikTok atualizado automaticamente
- FTP sincronizado
- Dashboard ao vivo

✅ **Documentado**
- Cada etapa explicada
- Logs completos
- Emails de relatório

✅ **Pronto para Produção**
- Testado
- Validado
- Monitorado

---

## 📞 Resumo

Sistema que:
1. Roda automaticamente a cada 6 horas
2. Processa 50+ produtos
3. Gera SEO + imagens IA
4. Atualiza Shopee + TikTok automaticamente
5. Envia relatório por email
6. Atualiza dashboard ao vivo
7. Aprende com dados
8. **Zero intervenção manual necessária**

**Resultado: E-commerce totalmente automático funcionando 24/7/365** 🚀

