# ✅ Checklist de Implementação — Automação de Produto

**Data de Início:** 2026-07-09  
**Data Alvo de Conclusão:** 2026-07-23 (2 semanas)  
**Responsável:** Você + Claude Code

---

## 🚀 SEMANA 1: PREPARAÇÃO DA INFRAESTRUTURA

### DIA 1: Configurar Tiny ERP

**Duração:** 30 minutos

- [ ] Acessar https://app.tiny.com.br/
- [ ] Ir para ⚙️ Configurações > Suprimentos > Campos Customizados
- [ ] **Opção A (Manual):** Criar 17 campos manualmente conforme tabela em AUTOMACAO-PRODUTO.md
- [ ] **Opção B (Automático):** Rodar script (requer token API):
  ```bash
  php scripts/setup-tiny-fields.php
  ```
- [ ] Confirmar todos os campos aparecem em Configurações
- [ ] ✅ Print screen dos campos criados

**Campos esperados:**
```
titulo_meli, desc_meli, titulo_shopee, desc_shopee,
titulo_amazon, bullet_1, bullet_2, bullet_3,
titulo_tiktok, desc_tiktok, ean_gemini,
peso_g, altura_cm, largura_cm, comprimento_cm,
url_bg_chat, status_automacao
```

---

### DIA 2: Gerar Chave de API Tiny

**Duração:** 10 minutos

- [ ] Em Tiny: ⚙️ > E-commerce > Integrações
- [ ] Encontrar "Hub Olist"
- [ ] Copiar "Chave de API"
- [ ] Salvar em arquivo seguro: `TINY_ERP_API_KEY=xxxxx`
- [ ] Adicionar ao `.env` local (nunca commitar .env com senhas!)

**Verificação:**
```bash
php scripts/validate-automation-setup.php
```

Deve mostrar: ✅ Tiny ERP — Autenticado

---

### DIA 3: Configurar Hub Olist

**Duração:** 1 hora

Para **CADA marketplace** (Mercado Livre, Shopee, Amazon, TikTok Shop):

1. **Acessar:** https://hub.olist.com.br/
2. **Canais Integrados** → Selecionar marketplace
3. **Mapeamento de Campos:**
   - [ ] Desmarcar "Espelhar dados globais do ERP"
   - [ ] Mapear conforme tabela abaixo:

#### Mercado Livre
```
Título → titulo_meli
Descrição → desc_meli
EAN → ean_gemini
Altura → altura_cm
Largura → largura_cm
Profundidade → comprimento_cm
Peso → peso_g
```

#### Shopee
```
Título → titulo_shopee
Descrição → desc_shopee
Imagem Capa → url_bg_chat
```

#### Amazon
```
Título → titulo_amazon
Bullet 1 → bullet_1
Bullet 2 → bullet_2
Bullet 3 → bullet_3
EAN → ean_gemini
```

#### TikTok Shop
```
Título → titulo_tiktok
Descrição → desc_tiktok
Imagem → url_bg_chat
```

4. **Ativar Webhooks:**
   - [ ] ✅ Publicar quando SKU criado
   - [ ] ✅ Atualizar quando modificado
   - [ ] ✅ Sincronizar estoque

**Verificação:** Print screen dos mapeamentos

---

### DIA 4-5: Preparar Credenciais de IA

**Duração:** 20 minutos

Você deve ter as 4 chaves de API:

- [ ] **GEMINI_API_KEY**
  - Acesse: https://ai.google.dev/
  - Create API Key
  - Copiar e salvar em `.env`

- [ ] **ANTHROPIC_API_KEY**
  - Acesse: https://console.anthropic.com/
  - Account > API keys
  - Create key
  - Copiar e salvar em `.env`

- [ ] **OPENAI_API_KEY**
  - Acesse: https://platform.openai.com/api/keys
  - Create new secret key
  - Copiar e salvar em `.env`

- [ ] **GOOGLE_DRIVE_FOLDER_ID**
  - Criar pasta "Novos_Produtos" no Google Drive
  - Abrir pasta
  - URL: https://drive.google.com/drive/folders/**{ID}**
  - Copiar ID e salvar em `.env`

**Verificação:**
```bash
php scripts/validate-automation-setup.php
```

Deve mostrar:
```
✅ Google Gemini — OK
✅ Claude API — OK
✅ OpenAI API — OK
✅ Tiny ERP — Autenticado
✅ Hub Olist — (verificar manualmente)
✅ Google Drive — OK
```

---

## 🤖 SEMANA 2: CONSTRUIR PIPELINE NO MAKE.COM

### DIA 8: Criar Conta Make.com e Primeiro Módulo

**Duração:** 1 hora

- [ ] Acessar https://www.make.com/
- [ ] Sign up ou login (conta gratuita ou paga)
- [ ] Criar novo "Scenario": `ShopVivaliz Auto-Product`

**MÓDULO 1: Google Drive — Watch New Files**

```
Trigger: Quando novo arquivo criado em /Novos_Produtos/

Configuração:
  - Folder ID: {seu ID da pasta}
  - Type: Only images
  - Scheduled: Check every 5 minutes
```

- [ ] Salvar módulo
- [ ] Testar: Upload 1 imagem fake na pasta → Verificar se trigger dispara

---

### DIA 9: Adicionar Módulo Gemini

**Duração:** 1 hora

- [ ] Clicar no "+" para adicionar novo módulo
- [ ] Buscar: "Google Gemini"
- [ ] Selecionar "Generate Content (Multimodal)"

**Configuração:**
```
API Key: {sua GEMINI_API_KEY}
Input File: webContentLink (output do Módulo 1)
Prompt: (ver AUTOMACAO-PRODUTO.md, "MÓDULO 2")
```

- [ ] Testar: Rodar cenário com imagem fake
- [ ] Verificar output JSON com marca/modelo/EAN

---

### DIA 10: Adicionar Módulo Claude

**Duração:** 1 hora

- [ ] Adicionar novo módulo
- [ ] Buscar: "Anthropic" ou "Claude"
- [ ] Selecionar "Create a Message"

**Configuração:**
```
API Key: {sua ANTHROPIC_API_KEY}
Model: claude-3-5-sonnet-20241022
Prompt: (ver AUTOMACAO-PRODUTO.md, "MÓDULO 3")
```

- [ ] Mapear inputs: {{marca}}, {{modelo}}, {{categoria}}, etc do Gemini
- [ ] Testar: Verificar JSON com título + desc para 4 marketplaces

---

### DIA 11: Adicionar Módulo ChatGPT/DALL-E

**Duração:** 1 hora

- [ ] Adicionar novo módulo
- [ ] Buscar: "OpenAI"
- [ ] Selecionar "Generate Image"

**Configuração:**
```
API Key: {sua OPENAI_API_KEY}
Model: dall-e-3
Size: 1024x1024
Quality: hd
Prompt: (ver AUTOMACAO-PRODUTO.md, "MÓDULO 4")
```

- [ ] Testar: Verificar URL de imagem gerada

**⚠️ AVISO:** DALL-E custa $0.080 por imagem. Usar com moderação em testes.

---

### DIA 12: Adicionar Módulo Tiny API

**Duração:** 1.5 horas

- [ ] Adicionar novo módulo
- [ ] Selecionar "HTTP" → "Make a request"

**Configuração:**
```
URL: https://tiny.com.br/api/v3/produtos/
Method: POST
Headers: Authorization: Bearer {TINY_ERP_API_KEY}

Body: (ver AUTOMACAO-PRODUTO.md, "MÓDULO 5")
```

- [ ] Mapear todos os campos customizados
- [ ] Testar: Executar pipeline completo
- [ ] Verificar SKU criado no Tiny

---

### DIA 13-14: Testes e Ajustes

**Duração:** 2 horas

- [ ] Testar fluxo completo 5 vezes com imagens diferentes
- [ ] Verificar se Hub Olist publica automaticamente
- [ ] Conferir produtos aparecem nos 4 marketplaces
- [ ] Ajustar prompts de IA se necessário

**Testes locais:**
```bash
# Teste cada etapa isoladamente
php scripts/test-automation-pipeline.php /caminho/imagem.jpg
```

---

## 📊 SEMANA 3: MONITORAMENTO E OTIMIZAÇÃO

### DIA 15-16: Setup de Monitoramento

**Duração:** 1.5 horas

- [ ] Criar script de preço dinâmico: `scripts/auto-price-optimizer.php`
- [ ] Criar script de A/B de imagem: `scripts/auto-image-ab.php`
- [ ] Configurar cron job (a cada 7 dias):
  ```bash
  0 0 * * 0 php /var/www/scripts/auto-price-optimizer.php
  ```

---

### DIA 17: Documentação e Entrega

**Duração:** 1 hora

- [ ] Criar documento: "Operação Diária" (como adicionar novo produto)
- [ ] Criar documento: "Troubleshooting" (problemas comuns)
- [ ] Fazer backup de dados
- [ ] Apresentar sistema funcionando

---

## 🧪 TESTES FINAIS

### Checklist de Validação

- [ ] **Teste 1:** Salvar 1 foto em Novos_Produtos → Verificar se aparece em todos os 4 marketplaces em < 10 minutos
- [ ] **Teste 2:** Ajustar preço manualmente no Tiny → Verificar se atualiza nos marketplaces em < 5 minutos
- [ ] **Teste 3:** Sem vendas em 7 dias → Verificar se preço reduz 10% automaticamente
- [ ] **Teste 4:** CTR baixo → Verificar se gera nova imagem e atualiza
- [ ] **Teste 5:** Simular pedido → Verificar se rastreia conversão de variante (A/B testing)

---

## 📋 DEPENDÊNCIAS E CUSTOS

| Item | Custo | Necessário | Status |
|------|-------|-----------|--------|
| Make.com (gratuito/pago) | $0-15/mês | ✅ Sim | - |
| Google Gemini API | Gratuito | ✅ Sim | - |
| Claude API | $3-20/mês | ✅ Sim | Já tem |
| OpenAI API (DALL-E) | $0.08/imagem | ✅ Sim | - |
| Tiny ERP | $50-150/mês | ✅ Sim | Já tem |
| Hub Olist | Gratuito | ✅ Sim | Já tem |
| Google Drive | Gratuito | ✅ Sim | - |

**Total Mensal:** ~$100-200 (principalmente Make + IA APIs)

---

## 🚨 PROBLEMAS COMUNS E SOLUÇÕES

### Make.com não conecta ao Tiny

**Solução:**
1. Verificar token TINY_ERP_API_KEY
2. Testar em Postman: `GET https://tiny.com.br/api/v3/contatos`
3. Se falhar, regenerar token no Tiny

### Gemini não reconhece imagem

**Solução:**
1. Imagem deve estar entre 100KB-4MB
2. Formato: JPEG, PNG, WebP, GIF
3. Deve conter produto claro (não foto de tela)

### DALL-E gera imagem com texto/logo

**Solução:**
1. Ajustar prompt: adicionar "NO text, NO logos, NO watermarks"
2. Usar modelo melhor se budget permite (dall-e-4)

### Hub Olist não publica

**Solução:**
1. Confirmar mapeamento de campos está CORRETO
2. Confirmar SKU foi criado com TODOS os campos obrigatórios
3. Verificar se webhook está ATIVADO no Hub
4. Aguardar 1-5 minutos (publicação não é instantânea)

---

## 📞 SUPORTE

**Script para validar tudo:**
```bash
php scripts/validate-automation-setup.php
```

**Script para testar pipeline:**
```bash
php scripts/test-automation-pipeline.php /caminho/imagem.jpg
```

**Script para criar campos:**
```bash
php scripts/setup-tiny-fields.php
```

---

## 🎯 MILESTONE CHECKLIST

- [ ] **Semana 1:** Infraestrutura pronta (Tiny + Hub Olist + Credenciais)
- [ ] **Semana 2:** Make.com pipeline funcionando fim-a-fim
- [ ] **Semana 3:** Monitoramento automático ativo
- [ ] **Final:** Sistema em produção, publicando automaticamente

---

**Status Atual:** 🚀 Iniciando  
**Próximo Passo:** DIA 1 — Configurar Tiny ERP (campos customizados)

**Boa sorte! Você consegue! 💪**

---

*Checklist criado por: Claude Code*  
*Última atualização: 2026-07-09*
