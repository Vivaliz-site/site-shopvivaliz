# 🛍️ Guia de Integração - Shopee e TikTok Shop

## 📊 Status Atual da Verificação (29/06/2026)

```
🛍️  SHOPEE
├─ Status: ⚠️  NÃO CONFIGURADA
├─ Credenciais Faltando: SHOPEE_PARTNER_ID, SHOPEE_PARTNER_KEY
└─ Itens a Verificar: 165 produtos

🎵 TIKTOK SHOP  
├─ Status: ⚠️  NÃO CONFIGURADA
├─ Credenciais Faltando: TIKTOK_CLIENT_ID, TIKTOK_CLIENT_SECRET
└─ Itens a Verificar: 165 produtos

⚡ OTIMIZAÇÃO DE IMAGENS
├─ Total de Produtos: 165
├─ Com 4 Imagens: 0
├─ Com Imagens Parciais: 165 (apenas 1 imagem cada)
└─ Taxa de Completude: 0% ⏳ (aguardando processamento)
```

---

## 🛍️ INTEGRAÇÃO SHOPEE

### Passo 1: Criar Conta de Desenvolvedor

1. Acesse [Shopee Partner](https://partner.shopee.com.br/)
2. Faça login com sua conta de seller
3. Vá em **Configurações** > **Configurações da Loja**
4. Procure por **API** ou **Integração de Desenvolvedor**

### Passo 2: Obter Credenciais

Na página de API, você encontrará:

```
SHOPEE_PARTNER_ID: 1237032
SHOPEE_PARTNER_KEY: shpk574f454f6a756e534e7476726b67727a5242554c76736d4b56567769554d
```

**Observações:**
- ⚠️ Guarde essas credenciais com segurança
- 🔒 Nunca compartilhe no repositório
- 🔄 Pode regenerá-las se necessário

### Passo 3: Configurar no GitHub Secrets

```bash
gh secret set SHOPEE_PARTNER_ID
# Copie e cole: 1237032

gh secret set SHOPEE_PARTNER_KEY  
# Copie e cole: shpk574f454f6a756e534e7476726b67727a5242554c76736d4b56567769554d
```

Ou via interface web:
- https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions
- New repository secret
- Name: `SHOPEE_PARTNER_ID`
- Secret: `1237032`

### Passo 4: Testar Conexão

```bash
python scripts/verify_marketplace_upload.py
```

**Resultado esperado:**
```
✅ SHOPEE VERIFICATION
✅ SHOPEE PARTNER_ID: 1237032
✅ 165 produtos verificados
✅ Imagens prontas para upload
```

### Passo 5: Upload de Imagens

O pipeline executará automaticamente:
1. Conectar ao Shopee Partner API
2. Fazer upload das 4 variantes de imagem
3. Atualizar o anúncio com a imagem principal (fundo branco)
4. Gerar relatório de sucesso

---

## 🎵 INTEGRAÇÃO TIKTOK SHOP

### Passo 1: Criar Conta de Desenvolvedor

1. Acesse [TikTok Seller Center](https://seller.tiktok.com/)
2. Faça login com sua conta de seller
3. Vá em **Configurações** > **Integração de APIs**
4. Clique em **Criar Novo App**

### Passo 2: Preencher Informações do App

```
Nome da Aplicação: ShopVivaliz Pipeline
Descrição: Integração automática de produtos e imagens
Tipo: E-commerce Integration
URL Callback: https://shopvivaliz.com.br/callback/tiktok
```

### Passo 3: Obter Credenciais

Após criar o app, você receberá:

```
TIKTOK_CLIENT_ID: 7xxxxxxxxxxxxx
TIKTOK_CLIENT_SECRET: xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

**Observações:**
- ⚠️ Guarde essas credenciais com segurança
- 🔒 Nunca compartilhe no repositório
- 🔄 Pode regenerá-las se necessário

### Passo 4: Configurar Permissions/Scopes

No painel do app, habilite as permissões:
- ✅ `shop.info`
- ✅ `product.read`
- ✅ `product.write`
- ✅ `image.upload`
- ✅ `order.read`

### Passo 5: Configurar no GitHub Secrets

```bash
gh secret set TIKTOK_CLIENT_ID
# Copie e cole: 7xxxxxxxxxxxxx

gh secret set TIKTOK_CLIENT_SECRET
# Copie e cole: xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### Passo 6: Testar Conexão

```bash
python scripts/verify_marketplace_upload.py
```

**Resultado esperado:**
```
✅ TIKTOK SHOP VERIFICATION
✅ TIKTOK_CLIENT_ID: 7xxxxxxxxxxxxx
✅ 165 produtos verificados
✅ Imagens prontas para upload
```

### Passo 7: Upload de Imagens

O pipeline executará automaticamente:
1. Conectar ao TikTok Shop API
2. Fazer upload das 4 variantes de imagem
3. Atualizar o anúncio com a imagem principal (lifestyle)
4. Gerar relatório de sucesso

---

## 📋 Checklist de Configuração

### Shopee ✓
- [ ] Acesso a Partner.shopee.com.br
- [ ] Obter SHOPEE_PARTNER_ID
- [ ] Obter SHOPEE_PARTNER_KEY
- [ ] Configurar GitHub Secrets
- [ ] Testar com verify_marketplace_upload.py
- [ ] Confirmar upload em um anúncio

### TikTok Shop ✓
- [ ] Acesso a Seller.tiktok.com
- [ ] Criar aplicação de desenvolvedor
- [ ] Obter TIKTOK_CLIENT_ID
- [ ] Obter TIKTOK_CLIENT_SECRET
- [ ] Habilitar permissões de API
- [ ] Configurar GitHub Secrets
- [ ] Testar com verify_marketplace_upload.py
- [ ] Confirmar upload em um anúncio

---

## 🔍 Verificação de Upload

Após configurar as credenciais, execute:

```bash
# Verificar se imagens foram upadas
python scripts/verify_marketplace_upload.py

# Exemplo de saída
📊 VERIFICAÇÃO DE MARKETPLACE
├─ 🛍️ Shopee: 165 produtos verificados
├─ 🎵 TikTok: 165 produtos verificados
└─ ⚡ Taxa de Completude: 100% (todas as 4 imagens por produto)
```

---

## 📸 Verificação Manual nos Marketplaces

### Shopee - Verificar um Produto

1. Acesse [Shopee.com.br](https://shopee.com.br/)
2. Busque por um produto do seu catálogo (ex: "JVAQAC44")
3. Verifique:
   - ✅ Imagem principal: fundo branco (hero shot)
   - ✅ Segunda imagem: ângulo 45°
   - ✅ Terceira imagem: lifestyle
   - ✅ Quarta imagem: close-up
4. Compare com o arquivo local em `storage/processed/JVAQAC44/`

### TikTok Shop - Verificar um Produto

1. Acesse [TikTok Shop](https://shop.tiktok.com/)
2. Busque por um produto do seu catálogo (ex: "JVNTI55")
3. Verifique:
   - ✅ Imagem principal: lifestyle (para feed do TikTok)
   - ✅ Imagens adicionais: outras variantes
4. Compare com o arquivo local em `storage/processed/JVNTI55/`

---

## 📊 Dados de Teste

Para testar a integração, use estes produtos:

### Shopee - Produtos para Testar
- JVAQAC44 (Assento)
- JVNTI55 (Outro produto)
- VCRAC2 (Terceiro produto)

### TikTok Shop - Produtos para Testar
- JFUBCQ10
- 1C7Q-LKVM-XFLJ
- 1C7Q-LKWN-58U7

---

## 🔧 Troubleshooting

### "Credenciais inválidas"
```
✓ Verifique se copou corretamente
✓ Confira em GitHub Secrets
✓ Regenere as credenciais no painel
✓ Teste com: python scripts/verify_secrets.py
```

### "Upload falhou"
```
✓ Verifique permissões de API
✓ Confira limite de requisições
✓ Verifique tamanho das imagens
✓ Consulte logs em logs/marketplace_upload.log
```

### "Imagens não aparecem"
```
✓ Aguarde 5-10 minutos para sincronizar
✓ Limpe cache do navegador
✓ Recarregue a página do produto
✓ Verifique em "Gerenciar Produtos"
```

---

## 📈 Automação

Após configurar as credenciais, o pipeline rodará automaticamente:

### GitHub Actions - Execution Schedule
```
# Executa a cada 6 horas
- 00:00 UTC - Sync Olist + Upload Imagens
- 06:00 UTC - Sync Olist + Upload Imagens
- 12:00 UTC - Sync Olist + Upload Imagens
- 18:00 UTC - Sync Olist + Upload Imagens

# Executa a cada push para main
- On Push - Deploy imagens via FTP
```

### Workflow Automático
```
1. GitHub Actions Dispara
   ↓
2. Import Shopee
   ↓
3. Generate AI Images (4 variantes)
   ↓
4. A/B Test Analysis
   ↓
5. Auto Optimize
   ↓
6. Upload Shopee API
   ↓
7. Upload TikTok API
   ↓
8. Generate Report + Send Email
```

---

## 📞 Suporte

### Problemas com Shopee?
- 📧 Email: [developer@shopee.com.br](mailto:developer@shopee.com.br)
- 🔗 Docs: [Shopee Developer](https://partner.shopee.com/docs)
- 💬 Forum: [Shopee Partner Community](https://forum.shopee.com.br/)

### Problemas com TikTok?
- 📧 Email: [seller-support@tiktok.com](mailto:seller-support@tiktok.com)
- 🔗 Docs: [TikTok Shop Developer](https://seller.tiktok.com/docs)
- 💬 Community: [TikTok Seller Community](https://www.tiktok.com/seller)

---

## ✅ Resultado Esperado

Após completar todos os passos:

```
🎉 PIPELINE COMPLETO EM PRODUÇÃO

✅ Shopee
   └─ 165 produtos com 4 imagens cada
   └─ Imagens sincronizadas automaticamente
   └─ A/B Testing em andamento

✅ TikTok Shop
   └─ 165 produtos com 4 imagens cada
   └─ Imagens sincronizadas automaticamente
   └─ Performance sendo monitorada

✅ Automação
   └─ Executa a cada 6 horas
   └─ Sincroniza novos produtos
   └─ Otimiza imagens ruins
   └─ Envia relatórios por email
```

---

**Última Atualização:** 29/06/2026
**Status:** ⏳ Aguardando configuração de credenciais
