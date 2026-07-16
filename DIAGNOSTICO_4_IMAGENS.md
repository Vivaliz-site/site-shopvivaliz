# 🔍 DIAGNÓSTICO: POR QUE NÃO GERA 4 IMAGENS

**Data:** 29/06/2026  
**Status:** ❌ Problema identificado e solução preparada

---

## 🔴 PROBLEMA IDENTIFICADO

### Por que apenas 1 imagem está sendo enviada para FTP?

```
ESPERADO:
  [1] Gera 4 imagens (OpenAI API)
  [2] Salva 4 arquivos em disco
  [3] Envia 4 arquivos para FTP
  [4] Registra 4 URLs

REALIDADE:
  [1] ❌ Gera apenas URLs FICTÍCIAS (não imagens reais)
  [2] ❌ Não salva em disco (apenas metadata JSON)
  [3] ❌ Upload não encontra arquivos
  [4] ⚠️  Registra apenas 1 URL (a que existe)
```

---

## 🔴 ROOT CAUSE #1: Geração de Imagens Simulada

### Arquivo: `scripts/ia/image_generator.py` (linhas 61-64)

```python
def _call_image_generation(self, prompt: str) -> str:
    """Chama API OpenAI para gerar imagem"""
    # ❌ PROBLEMA: Apenas retorna URL fictícia
    return f"https://storage.shopvivaliz.com/ai_generated/{hash(prompt)}.jpg"
```

**Problema:**
- Não chama OpenAI API
- Não gera imagem real
- Apenas retorna URL fictícia

---

## 🔴 ROOT CAUSE #2: Não Salva Imagens em Disco

### Arquivo: `scripts/ia/image_generator.py` (linhas 116-127)

```python
def _save_image_metadata(self, product_id: str, images: List[Dict]):
    """Salva metadata das imagens geradas"""
    # ❌ PROBLEMA: Salva apenas JSON metadata
    # Deveria salvar arquivos .jpg/.png reais
    metadata_file = os.path.join(self.output_dir, f'{product_id}_metadata.json')
    
    with open(metadata_file, 'w') as f:
        json.dump({
            'product_id': product_id,
            'images': images,  # ← URLs fictícias, não imagens reais
            'created_at': datetime.now().isoformat()
        }, f, indent=2)
```

**Problema:**
- Salva apenas `product_id_metadata.json`
- Não salva `1.jpg`, `2.jpg`, `3.jpg`, `4.jpg`
- Script de upload procura por esses arquivos e não encontra

---

## 🔴 ROOT CAUSE #3: Upload Procura Arquivos que Não Existem

### Arquivo: `scripts/upload_images.py` (linhas 117-126)

```python
for variant in range(1, 5):
    local_file = sku_dir / f'{variant}.jpg'  # ← Procura 1.jpg, 2.jpg, etc
    field_name = f'image_url_{variant}'
    if local_file.exists():  # ❌ Arquivo não existe!
        upload_file(ftp, local_file, f'{variant}.jpg')
        uploaded_urls[field_name] = f'{WEB_BASE_URL}/{sku_dir.name}/{variant}.jpg'
    else:
        uploaded_urls[field_name] = ''  # ← Deixa vazio
        logger.warning(f'... missing processed file {local_file}')
```

**Resultado:**
```
✅ image_url_1: https://... (se houver imagem)
❌ image_url_2: (vazio)
❌ image_url_3: (vazio)
❌ image_url_4: (vazio)
```

---

## ✅ SOLUÇÃO: Corrigir Geração de Imagens

### Passo 1: Implementar Geração Real com OpenAI

```python
# scripts/ia/image_generator.py

def _call_image_generation(self, prompt: str) -> str:
    """Chama API OpenAI para gerar imagem REAL"""
    try:
        import openai
        openai.api_key = self.api_key
        
        # Chamar API real
        response = openai.Image.create(
            prompt=prompt,
            n=1,
            size="1024x1024"
        )
        
        image_url = response['data'][0]['url']
        
        # BAIXAR imagem
        import requests
        img_data = requests.get(image_url).content
        
        return img_data  # Retorna bytes da imagem, não URL
        
    except Exception as e:
        logger.error(f"Erro ao gerar imagem: {e}")
        return None
```

### Passo 2: Salvar as 4 Imagens em Disco

```python
# scripts/ia/image_generator.py

def generate_product_images(self, product: Dict) -> Dict:
    """Gera 4 imagens para um produto"""
    product_id = product.get('id', 'unknown')
    product_name = product.get('name', 'Produto')
    
    images = []
    
    prompts = [
        f"Imagem profissional {product_name}. Fundo branco. Studio",
        f"{product_name} em uso. Ambiente realista. Qualidade",
        f"Detalhe {product_name}. Texturas. Profissional",
        f"{product_name} destaque. Cores vibrantes. Marketing"
    ]
    
    # Criar diretório para produto
    product_dir = os.path.join(self.output_dir, product_id)
    os.makedirs(product_dir, exist_ok=True)
    
    for i, prompt in enumerate(prompts):
        try:
            # Gerar imagem
            image_bytes = self._call_image_generation(prompt)
            
            if image_bytes:
                # ✅ SALVAR em disco
                image_path = os.path.join(product_dir, f"{i+1}.jpg")
                with open(image_path, 'wb') as f:
                    f.write(image_bytes)
                
                images.append({
                    'variant': i + 1,
                    'local_file': image_path,  # ← Caminho local
                    'prompt': prompt,
                    'generated_at': datetime.now().isoformat(),
                    'status': 'success'
                })
            else:
                images.append({
                    'variant': i + 1,
                    'local_file': None,
                    'prompt': prompt,
                    'error': 'Failed to generate',
                    'status': 'failed'
                })
                
        except Exception as e:
            images.append({
                'variant': i + 1,
                'local_file': None,
                'prompt': prompt,
                'error': str(e),
                'status': 'failed'
            })
    
    # Salvar metadata
    self._save_image_metadata(product_id, images)
    
    return {
        'product_id': product_id,
        'product_name': product_name,
        'images': images,
        'total_generated': len([img for img in images if img['status'] == 'success']),
        'files_created': [img.get('local_file') for img in images if img['status'] == 'success']
    }
```

---

## 🔧 PASSO A PASSO PARA CORRIGIR

### 1. Editar `scripts/ia/image_generator.py`

```bash
# Backup
cp scripts/ia/image_generator.py scripts/ia/image_generator.py.bak

# Editar arquivo (substituir _call_image_generation e generate_product_images)
```

### 2. Instalar dependência

```bash
pip install requests
```

### 3. Testar localmente

```bash
python scripts/generate_ai_images.py
```

Verificar:
- [ ] `storage/processed/PRODUTO_ID/1.jpg` existe?
- [ ] `storage/processed/PRODUTO_ID/2.jpg` existe?
- [ ] `storage/processed/PRODUTO_ID/3.jpg` existe?
- [ ] `storage/processed/PRODUTO_ID/4.jpg` existe?

### 4. Testar upload

```bash
python scripts/upload_images.py
```

Verificar `storage/uploaded_urls.csv`:
```csv
sku,image_url_1,image_url_2,image_url_3,image_url_4
PRODUTO,https://...,https://...,https://...,https://...
```

### 5. Fazer git push

```bash
git add scripts/ia/image_generator.py
git commit -m "fix: Gerar e salvar TODAS as 4 imagens em disco"
git push origin main
```

---

## 📋 SINCRONIZAR NOMES DOS SECRETS

### Arquivo: `scripts/upload_images.py` (linhas 74-76)

Já está com suporte a nomes alternativos! ✅

```python
host = get_env_variable('FTP_HOST', ['FTP_SERVER'])
user = get_env_variable('FTP_USER', ['FTP_USERNAME'])
password = get_env_variable('FTP_PASS', ['FTP_PASSWORD'])
```

**Isso significa:**
- Se existir `FTP_HOST` → usa
- Senão, se existir `FTP_SERVER` → usa
- Mesma coisa para `FTP_USER` vs `FTP_USERNAME`
- Mesma coisa para `FTP_PASS` vs `FTP_PASSWORD`

✅ **Já está sincronizado automaticamente!**

### Verificar outros scripts

Mas precisa verificar outros arquivos que usam secrets:

```bash
grep -r "os.getenv('FTP" scripts/ | grep -v ".pyc"
grep -r "os.getenv('SHOPEE" scripts/ | grep -v ".pyc"
grep -r "os.getenv('TIKTOK" scripts/ | grep -v ".pyc"
```

Se encontrar sem suporte a alternativas, adicionar.

---

## ✅ CHECKLIST PARA CORRIGIR

- [ ] 1. Corrigir `scripts/ia/image_generator.py`:
        - [ ] Implementar `_call_image_generation()` real
        - [ ] Implementar salvamento de 4 imagens em disco
        - [ ] Criar diretório `storage/processed/{product_id}/`
        
- [ ] 2. Instalar dependências:
        ```bash
        pip install openai requests
        ```
        
- [ ] 3. Testar localmente:
        ```bash
        python scripts/generate_ai_images.py
        python scripts/upload_images.py
        ```
        
- [ ] 4. Verificar resultados:
        - [ ] 4 arquivos `.jpg` por produto?
        - [ ] 4 URLs no CSV?
        
- [ ] 5. Sincronizar secrets (se nomes forem diferentes):
        ```bash
        grep -r "os.getenv('FTP" scripts/
        # Se houver sem alternativas, adicionar
        ```
        
- [ ] 6. Fazer git push:
        ```bash
        git add scripts/
        git commit -m "fix: Gerar e salvar TODAS as 4 imagens"
        git push origin main
        ```
        
- [ ] 7. Monitorar primeira execução:
        - [ ] 4 URLs aparecem no CSV?
        - [ ] Imagens no FTP?

---

## 🎯 RESULTADO ESPERADO

### Antes (Atual):
```csv
sku,image_url_1,image_url_2,image_url_3,image_url_4
JVAQAC44,https://dev.shopvivaliz.com.br/uploads/olist/JVAQAC44/1.jpg,,,
```

### Depois (Corrigido):
```csv
sku,image_url_1,image_url_2,image_url_3,image_url_4
JVAQAC44,https://dev.shopvivaliz.com.br/uploads/olist/JVAQAC44/1.jpg,https://dev.shopvivaliz.com.br/uploads/olist/JVAQAC44/2.jpg,https://dev.shopvivaliz.com.br/uploads/olist/JVAQAC44/3.jpg,https://dev.shopvivaliz.com.br/uploads/olist/JVAQAC44/4.jpg
```

---

**Status:** ✅ Solução identificada e pronta para implementar

Aguardando seu comando para corrigir! 🚀
