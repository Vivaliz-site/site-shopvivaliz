# 📤 STATUS DE UPLOAD PARA FTP

**Data:** 29/06/2026  
**Status:** ⚠️ Parcialmente Implementado  
**Problema:** Apenas 1 imagem por produto, precisa ser 4 variantes

---

## ❌ PROBLEMA ATUAL

```
ESPERADO:
  SKU: JVAQAC44
  ├─ Imagem 1 (Fundo branco)
  ├─ Imagem 2 (Ângulo 45°)
  ├─ Imagem 3 (Lifestyle)
  └─ Imagem 4 (Close-up)

REALIDADE:
  SKU: JVAQAC44
  └─ Apenas 1 imagem
```

**Arquivo:** `storage/uploaded_urls.csv`

```csv
sku,image_url_1,image_url_2,image_url_3,image_url_4
JVAQAC44,https://shopvivaliz.com.br/uploads/olist/JVAQAC44/1.jpg,,,
```

**Status:** ⚠️ Faltam 3 variantes!

---

## 🔍 O QUE ESTÁ FALTANDO

### 1. Não há envio das 4 variantes

```
Geradas:  ✅ 4 imagens por produto
Enviadas: ❌ Apenas 1 imagem por produto
Faltando: ❌ 3 variantes (1, 2, 3)
```

### 2. Não há separação de variantes

```
Esperado:
  JVAQAC44_1.png (branco)
  JVAQAC44_2.png (ângulo)
  JVAQAC44_3.png (lifestyle)
  JVAQAC44_4.png (close-up)

Real:
  1.jpg (genérico)
```

### 3. FTP não está recebendo as imagens

```
FTP Status: ⚠️ Arquivo existe mas está incompleto
Credenciais: 🔐 Não configuradas (GitHub Secrets)
Teste: ❌ Não foi testado com FTP_SERVER real
```

---

## 🛠️ COMO CORRIGIR

### Passo 1: Configurar FTP Credentials

```bash
Configure estes secrets no GitHub:

✅ FTP_SERVER
   Valor: ftp.seu-servidor.com.br

✅ FTP_USERNAME
   Valor: seu_usuario_ftp

✅ FTP_PASSWORD
   Valor: sua_senha_ftp

✅ FTP_PORT
   Valor: 21 (padrão)
```

### Passo 2: Atualizar Upload Script

Arquivo: `scripts/upload_images.py`

```python
# ADICIONAR:
for produto_id in produtos:
    # Gera 4 variantes
    img_1 = gerar_imagem(produto, "branco")
    img_2 = gerar_imagem(produto, "angulo_45")
    img_3 = gerar_imagem(produto, "lifestyle")
    img_4 = gerar_imagem(produto, "close_up")
    
    # ENVIAR TODAS 4 para FTP
    url_1 = upload_para_ftp(img_1, f"{produto_id}_1.png")
    url_2 = upload_para_ftp(img_2, f"{produto_id}_2.png")
    url_3 = upload_para_ftp(img_3, f"{produto_id}_3.png")
    url_4 = upload_para_ftp(img_4, f"{produto_id}_4.png")
    
    # REGISTRAR TODAS
    registrar_urls({
        "sku": produto_id,
        "image_url_1": url_1,
        "image_url_2": url_2,
        "image_url_3": url_3,
        "image_url_4": url_4
    })
```

### Passo 3: Usar URLs no A/B Test

```python
# DEPOIS:
# Selecionar melhor imagem baseado em CTR
melhor_imagem = selecionar_por_ctr([url_1, url_2, url_3, url_4])

# ENVIAR MELHOR para Shopee/TikTok
shopee_api.update_product(
    product_id,
    imagem=melhor_imagem  # ← USAR URL DO FTP
)
```

---

## 📋 ARQUIVO ESPERADO APÓS FIX

### storage/uploaded_urls.csv

```csv
sku,image_url_1,image_url_2,image_url_3,image_url_4
JVAQAC44,https://ftp.shopvivaliz.com.br/JVAQAC44_1.png,https://ftp.shopvivaliz.com.br/JVAQAC44_2.png,https://ftp.shopvivaliz.com.br/JVAQAC44_3.png,https://ftp.shopvivaliz.com.br/JVAQAC44_4.png
JVCDAC33,https://ftp.shopvivaliz.com.br/JVCDAC33_1.png,https://ftp.shopvivaliz.com.br/JVCDAC33_2.png,https://ftp.shopvivaliz.com.br/JVCDAC33_3.png,https://ftp.shopvivaliz.com.br/JVCDAC33_4.png
...
```

**Todas as 172 SKUs com 4 URLs cada = 688 URLs totais**

---

## 🚀 FLUXO CORRETO

```
[1] GERAR 4 IMAGENS
    └─ Imagem 1: Fundo branco
    └─ Imagem 2: Ângulo 45°
    └─ Imagem 3: Lifestyle
    └─ Imagem 4: Close-up

[2] ENVIAR TODAS 4 PARA FTP
    └─ JVAQAC44_1.png → FTP
    └─ JVAQAC44_2.png → FTP
    └─ JVAQAC44_3.png → FTP
    └─ JVAQAC44_4.png → FTP

[3] COLETAR 4 URLs
    └─ url_1 = https://ftp/.../JVAQAC44_1.png
    └─ url_2 = https://ftp/.../JVAQAC44_2.png
    └─ url_3 = https://ftp/.../JVAQAC44_3.png
    └─ url_4 = https://ftp/.../JVAQAC44_4.png

[4] A/B TEST (4 variantes)
    └─ Teste qual tem melhor CTR
    └─ Seleciona vencedora

[5] ENVIAR MELHOR PARA APIs
    └─ Shopee: url_melhor
    └─ TikTok: url_melhor

[6] VALIDAR MARKETPLACE
    └─ Confirma que imagem foi atualizada
```

---

## 📊 RESULTADO ESPERADO

### Por Produto:

```
SKU: JVAQAC44

Geradas:  ✅ 4 imagens
Enviadas: ✅ 4 URLs (FTP)
Testadas: ✅ 4 variantes (A/B)
Vencedora: ✅ 1 imagem selecionada
Enviada para Shopee: ✅ URL da vencedora
Enviada para TikTok: ✅ URL da vencedora
Validadas: ✅ Ambas APIs confirmam
```

### Total em 24h:

```
Produtos: 172 × 4 ciclos = 688
Imagens: 688 × 4 variantes = 2.752
URLs FTP: 2.752 (uma por imagem)
A/B Tests: 688 (4 variantes cada)
Imagens enviadas Shopee: 688
Imagens enviadas TikTok: 688
```

---

## ⚠️ O QUE PRECISA SER FEITO

### AGORA (Antes de configurar secrets):

1. **Verificar Upload Script**
   - [ ] Está gerando 4 imagens por produto?
   - [ ] Está enviando TODAS 4 para FTP?
   - [ ] Está registrando TODAS 4 URLs?

2. **Testar FTP Localmente**
   - [ ] Conectar ao FTP (quando credenciais forem configuradas)
   - [ ] Upload de arquivo teste
   - [ ] Confirmar acesso público (URL acessível)

3. **Validar URLs**
   - [ ] URLs do FTP abrem em navegador?
   - [ ] Imagens aparecem corretamente?
   - [ ] URLs funcionam nas APIs?

### DEPOIS (Quando secrets estiverem configurados):

1. **Fazer git push**
   ```bash
   git push origin main
   ```

2. **Sistema executará workflow**
   - GitHub Actions iniciará
   - Credenciais FTP serão injetadas
   - Upload FTP começará
   - URLs serão coletadas

3. **Monitorar primeira execução**
   - Verificar logs
   - Confirmar URLs do FTP
   - Validar imagens no marketplace

---

## 🎯 RESUMO DO PROBLEMA

```
❌ ATUAL:
   └─ Apenas 1 imagem por produto
   └─ Faltam 3 variantes
   └─ Não há A/B test completo
   └─ Falha na seleção de melhor imagem

✅ ESPERADO:
   └─ 4 imagens por produto
   └─ Todas enviadas para FTP
   └─ A/B test com 4 variantes
   └─ Melhor imagem selecionada automaticamente
   └─ Enviada para Shopee e TikTok
```

---

**Status:** ⚠️ PRECISA SER CORRIGIDO ANTES DO GO-LIVE

Aguardando:
1. Configuração de FTP Secrets
2. Verificação do script de upload
3. Teste de FTP
4. Primeira execução do workflow

Depois tudo funcionará 100%! 🚀
