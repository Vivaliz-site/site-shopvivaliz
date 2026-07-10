# 🔧 Guia de Troubleshooting — Automação de Produto

**Data:** 2026-07-09  
**Objetivo:** Resolver problemas comuns que surgem durante setup e testes  
**Ultima atualização:** 2026-07-09

---

## 📋 Índice Rápido de Problemas

- [Problemas de Setup Infraestrutura](#problemas-de-setup-infraestrutura)
- [Problemas com Tiny ERP](#problemas-com-tiny-erp)
- [Problemas com Hub Olist](#problemas-com-hub-olist)
- [Problemas com Make.com](#problemas-com-makecom)
- [Problemas com Google Drive](#problemas-com-google-drive)
- [Problemas com IAs (Gemini, Claude, OpenAI)](#problemas-com-ias)
- [Problemas de Publicação nos Marketplaces](#problemas-de-publicação-nos-marketplaces)
- [Problemas de Performance](#problemas-de-performance)
- [Debug Checklist](#debug-checklist)

---

## Problemas de Setup Infraestrutura

### ❌ Script de validação retorna "FALTAM CREDENCIAIS"

```bash
php scripts/validate-automation-setup.php
```

**Erro típico:**
```
❌ FALTAM CREDENCIAIS
  GEMINI_API_KEY=your_value_here
  ANTHROPIC_API_KEY=your_value_here
  OPENAI_API_KEY=your_value_here
```

**Causa:** Variáveis não estão em `.env`

**Solução:**

1. **Verificar localização do .env:**
   ```
   Deve estar em: C:\site-shopvivaliz\.env
   NÃO em: C:\site-shopvivaliz\.env.example
   ```

2. **Se .env não existe, criar:**
   ```bash
   cp .env.example .env
   ```

3. **Editar .env e adicionar credenciais:**
   ```
   GEMINI_API_KEY=seu_token_aqui
   ANTHROPIC_API_KEY=seu_token_aqui
   OPENAI_API_KEY=seu_token_aqui
   TINY_ERP_API_KEY=seu_token_aqui
   GOOGLE_DRIVE_FOLDER_ID=pasta_id_aqui
   ```

4. **Re-rodar validação:**
   ```bash
   php scripts/validate-automation-setup.php
   ```

**Se ainda falhar:**
- [ ] Verificar se .env está sendo lido corretamente
- [ ] Checar se não há BOM (Byte Order Mark) no arquivo
- [ ] Usar editor sem BOM (VSCode, Notepad++)

---

### ❌ "Token inválido" para API Tiny

**Erro típico:**
```
❌ Tiny ERP — Chave inválida
```

**Causas possíveis:**
1. Token copiado com espaços extras
2. Token expirado
3. Token revogado
4. Token de ambiente errado

**Solução:**

1. **Verificar se token foi copiado corretamente:**
   ```bash
   # Abrir .env
   # Procurar: TINY_ERP_API_KEY=xxxxx
   # Garantir sem espaços antes/depois
   ```

2. **Ir para Tiny e regenerar token:**
   ```
   1. Acesse: https://app.tiny.com.br/
   2. ⚙️ Configurações > E-commerce > Integrações
   3. Encontre: Hub Olist
   4. Clique em: "Gerar nova chave" ou "Regenerar"
   5. Copie exatamente (sem espaços)
   6. Paste em .env
   ```

3. **Testar com curl:**
   ```bash
   curl -X GET https://tiny.com.br/api/v3/contatos \
     -H "Authorization: Bearer SEU_TOKEN_AQUI"
   ```
   
   Se retornar `200 OK`, token é válido.

4. **Re-rodar validação:**
   ```bash
   php scripts/validate-automation-setup.php
   ```

---

### ❌ "Google Drive folder not accessible"

**Causa:** GOOGLE_DRIVE_FOLDER_ID inválido ou pasta não existe

**Solução:**

1. **Verificar se pasta existe:**
   - Acesse: https://drive.google.com/
   - Procure por pasta: `Novos_Produtos`
   - Se não existe, criar pasta com este nome EXATO

2. **Copiar ID correto da pasta:**
   ```
   Abra a pasta em Google Drive
   URL: https://drive.google.com/drive/folders/[ID_AQUI]
   Copie tudo depois de "folders/"
   ```

3. **Colocar em .env:**
   ```
   GOOGLE_DRIVE_FOLDER_ID=1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6p7
   ```

4. **Dar permissão ao Make.com:**
   - Na primeira execução, Make pede autorização Google
   - Autorize acesso à pasta Novos_Produtos
   - Google pedirá permissão: aceitar

5. **Testar novamente:**
   ```bash
   php scripts/validate-automation-setup.php
   ```

---

## Problemas com Tiny ERP

### ❌ Setup de campos customizados falha

```bash
php scripts/setup-tiny-fields.php
```

**Erro típico:**
```
❌ (HTTP 401)  # Token inválido
❌ (HTTP 403)  # Permissão insuficiente
❌ (HTTP 409)  # Campo já existe (OK)
```

**Solução por tipo de erro:**

**401 — Token Inválido:**
- Verificar TINY_ERP_API_KEY (ver seção acima)
- Regenerar token no Tiny
- Re-testar

**403 — Permissão Insuficiente:**
- [ ] Login no Tiny com usuário ADMIN
- [ ] Verificar se você é "Administrador" (não "Gerente")
- [ ] Se não for, solicitar ao administrador criar os campos
- [ ] Ou criar manualmente (mais lento, ver abaixo)

**409 — Campo Já Existe:**
- [ ] Isto é ESPERADO e OK
- [ ] Significa campo já foi criado antes
- [ ] Script pula para o próximo
- [ ] Resultado final deve mostrar: "Criados: 10, Já existentes: 7, Erros: 0"

**Criar Campos Manualmente (se script falhar):**

1. Acesse: https://app.tiny.com.br/
2. ⚙️ > Suprimentos > Campos Customizados
3. Clique em: "Novo Campo"
4. Adicione cada campo:
   ```
   Nome do Campo: titulo_meli
   Label: Título Mercado Livre
   Tipo: Texto
   Tamanho: 60
   Obrigatório: Não
   Clique: Salvar
   
   (Repetir para cada um dos 17 campos)
   ```

**Verificação final:**
- Após criar/rodar script, vá em: ⚙️ > Campos Customizados
- Deve listar 17 campos (aproximadamente)

---

### ❌ Campo customizado não aparece no formulário de produto

**Causa:** Campo criado mas não ativado

**Solução:**

1. Ir em: ⚙️ > Campos Customizados
2. Procurar pelo campo
3. Verificar se está: ☑️ Ativo
4. Se não está, marcar checkbox "Ativo"
5. Salvar

---

### ❌ "Erro ao conectar Hub Olist"

**Cause:** Integração Hub Olist não está ativa no Tiny

**Solução:**

1. Acesse Tiny: ⚙️ > E-commerce > Integrações
2. Procure por: "Hub Olist" ou "Olist"
3. Se não aparece, clique: "Adicionar Integração"
4. Selecione: "Hub Olist"
5. Autorize a conexão (redirecionará para Hub)
6. Volte ao Tiny e confirme

---

## Problemas com Hub Olist

### ❌ "Mapeamento de campos não aparece"

**Causa:** Marketplace não está conectado ou sincronização não funcionou

**Solução:**

1. **Verificar se marketplace está conectado:**
   ```
   Hub Olist > Canais Integrados
   Deve mostrar: "Mercado Livre - ✅ Conectado"
   Se não conectado: clique em "Conectar"
   ```

2. **Se conectado mas mapeamento não aparece:**
   - Atualizar página (F5)
   - Limpar cookies do navegador
   - Tentar em navegador diferente
   - Aguardar 5 minutos (Hub pode estar sincronizando)

3. **Se ainda não funcionar:**
   - Desconectar marketplace
   - Reconectar
   - Tentar novamente

---

### ❌ "Produto criado no Tiny mas não aparece no marketplace"

**Causa:** Webhook não está sincronizando

**Verificação:**

1. **Verificar se webhook está ativo:**
   ```
   Hub Olist > [Marketplace] > Webhooks
   Deve estar: ☑️ Ativo
   ```

2. **Verificar logs de sincronização:**
   ```
   Hub Olist > Histórico / Logs
   Procurar por SKU criado
   Ver se houve tentativa de sincronização
   Ver mensagem de erro (se houver)
   ```

3. **Causas comuns:**

   **A) Campo obrigatório vazio no Tiny:**
   ```
   Exemplo: Não preencheu "Título Mercado Livre"
   Solução: Editar produto no Tiny, preencher campo
   ```

   **B) Estoque zerado:**
   ```
   Alguns marketplaces não publicam se estoque = 0
   Solução: Garantir estoque >= 1 no Tiny
   ```

   **C) EAN inválido:**
   ```
   Se EAN foi extrado incorretamente por Gemini
   Solução: Remover EAN do campo (deixar vazio) ou entrar manualmente
   ```

   **D) Imagem URL inválida:**
   ```
   Se url_bg_chat tem URL incorreta
   Solução: Verificar se URL é acessível (abrir em navegador)
   ```

4. **Solução prática:**
   - Editar produto no Tiny
   - Garantir campos obrigatórios preenchidos
   - Salvar novamente
   - Hub tentará sincronizar novamente
   - Aguardar 5-10 minutos

---

### ❌ "Hub Olist sincroniza mas marketplace mostra erro"

**Causa:** Dados do Tiny não passam validação do marketplace

**Verificar:**

1. **No Hub Olist, ir em Logs:**
   - Procurar pelo SKU
   - Ver mensagem de erro específica

2. **Erros comuns por marketplace:**

   **Mercado Livre:**
   ```
   "Título muito curto" → Mínimo 10 caracteres
   "Descrição vazia" → Preencher desc_meli
   "EAN inválido" → Remover ou validar
   ```

   **Shopee:**
   ```
   "Título muito longo" → Máximo 120 caracteres
   "Imagem não acessível" → Verificar URL em navegador
   ```

   **Amazon:**
   ```
   "Bullet points muito curtos" → Mínimo 20 caracteres cada
   "EAN obrigatório" → Amazon exige EAN válido
   "Categoria não permitida" → Selecionar categoria correta
   ```

   **TikTok:**
   ```
   "Imagem de baixa qualidade" → DALL-E às vezes falha
   "Descrição muito curta" → Mínimo 50 caracteres
   ```

3. **Solução:**
   - Corrigir dados no Tiny
   - Salvar novamente
   - Hub tentará sincronizar automaticamente

---

## Problemas com Make.com

### ❌ "Módulo não conecta à API"

**Causa:** API Key inválida ou formato errado

**Solução:**

1. **Verificar se chave foi copiada SEM espaços:**
   ```bash
   # Em .env:
   GEMINI_API_KEY=abc123def456  # ✅ Correto (sem espaços)
   GEMINI_API_KEY= abc123def456  # ❌ Errado (espaço antes)
   GEMINI_API_KEY=abc123def456 # ❌ Errado (espaço depois)
   ```

2. **Testar chave em Postman:**
   - Abra Postman: https://www.postman.com/
   - Crie request GET para: `https://tiny.com.br/api/v3/contatos`
   - Header: `Authorization: Bearer SEU_TOKEN`
   - Se retorna 200 OK, token é válido

3. **Regenerar chave no painel da API:**
   - Alguns provedores exigem regeneração periódica
   - Gerar chave nova e atualizar em Make

4. **Testar novamente no Make:**
   - Clicar em conexão
   - Clique em "Test connection" (se disponível)
   - Deve retornar ✅

---

### ❌ "Módulo Gemini retorna erro de imagem"

**Erro típico:**
```
"Could not download image"
"Image format not supported"
"Image too large"
```

**Causa:** Problema com a imagem ou URL

**Solução:**

1. **Verificar tamanho e formato da imagem:**
   ```
   Tamanho: 100KB - 4MB
   Formato: JPEG, PNG, WebP, GIF
   Resolução: 64x64 até 4000x4000 (recomendado: 1024x1024)
   ```

2. **Verificar se URL é acessível:**
   - Copiar a URL: `webContentLink` do módulo Google Drive
   - Colar em navegador
   - Deve baixar/visualizar a imagem
   - Se retorna 404, problema no Google Drive

3. **Se Google Drive retorna erro:**
   ```
   1. Verificar se arquivo existe em /Novos_Produtos/
   2. Verificar permissão da pasta (deve ser público ou compartilhado)
   3. Tentar com arquivo diferente
   ```

4. **Teste com Gemini diretamente:**
   - Acesse: https://ai.google.dev/
   - Testar análise de imagem
   - Se funciona lá, problema está em Make
   - Se não funciona, problema é a imagem

---

### ❌ "Módulo Claude retorna JSON inválido"

**Erro típico:**
```json
{
  "error": "Invalid JSON in response",
  "response": "Lorem ipsum dolor sit amet..."
}
```

**Causa:** Claude retornou texto em vez de JSON

**Solução:**

1. **Verificar prompt:**
   - Certifique-se de que prompt termina com: `RETORNE APENAS JSON VÁLIDO`
   - Adicionar: `Sem explicações, sem markdown, APENAS JSON`

2. **Adicionar validação após Claude:**
   - No Make, após módulo Claude
   - Usar função: `parseJson()` para validar
   - Se falhar, ativar roteamento de erro

3. **Aumentar "Max Tokens" no Claude:**
   - Se resposta foi truncada, aumentar para 4000

4. **Tentar prompt simplificado:**
   ```
   Versão original pode ter chegado truncado
   Dividir em 2 prompts: um para ML/Shopee, outro para Amazon/TikTok
   ```

---

### ❌ "Módulo DALL-E gera imagem com texto/logo"

**Causa:** Prompt não foi claro o suficiente

**Solução:**

1. **Aumentar emphasis do prompt:**
   ```
   ANTES:
   "NO text, NO logos"
   
   DEPOIS:
   "ABSOLUTELY NO text, ABSOLUTELY NO logos, ABSOLUTELY NO watermarks"
   ```

2. **Remover exemplos que podem confundir:**
   - Se referencia a marca, pode gerar logo
   - Remover referências de brands específicas

3. **Usar modelo melhor (se orçamento permite):**
   ```
   dall-e-4 (mais caro mas melhor qualidade)
   vs
   dall-e-3 (padrão)
   ```

4. **Testar prompt em https://openai.com/dall-e-3:**
   - Teste o prompt diretamente
   - Se gera logo lá, problema é o prompt
   - Refinar até ficar bom

---

### ❌ "Módulo Tiny API retorna erro 400"

**Erro:** HTTP 400 Bad Request

**Causa:** Body JSON está inválido

**Solução:**

1. **Validar JSON:**
   - Copiar o body que está sendo enviado
   - Colar em: https://jsonlint.com/
   - Se retorna erro, corrigir estrutura

2. **Verificar tipos de dados:**
   ```json
   ❌ "preco": "75.50"    // String
   ✅ "preco": 75.50      // Número
   
   ❌ "quantidade": "1"   // String
   ✅ "quantidade": 1     // Número
   ```

3. **Verificar campos obrigatórios:**
   ```json
   Mínimo necessário para criar SKU:
   {
     "nome": "string obrigatório",
     "sku": "string obrigatório",
     "preco": número obrigatório
   }
   ```

4. **Testar com curl:**
   ```bash
   curl -X POST https://tiny.com.br/api/v3/produtos/ \
     -H "Authorization: Bearer SEU_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "produto": {
         "nome": "TESTE",
         "sku": "TEST-001",
         "preco": 75.50
       }
     }'
   ```

5. **Verificar resposta no Make:**
   - Make mostra resposta de erro detalhada
   - Copiar mensagem de erro
   - Procurar campo problemático e corrigir

---

### ❌ "Módulo Tiny API retorna erro 401"

**Erro:** HTTP 401 Unauthorized

**Causa:** Token TINY_ERP_API_KEY inválido

**Solução:**

1. **Verificar se token está na variável:**
   - Em Make, antes de fazer request
   - Adicionar step de "Log" para imprimir token
   - Verificar se aparece corretamente

2. **Regenerar token no Tiny:**
   - ⚙️ > E-commerce > Integrações > Hub Olist
   - Clicar em "Regenerar Chave"
   - Copiar nova chave
   - Atualizar em Make

3. **Verificar se token foi copiado SEM espaços**

---

## Problemas com Google Drive

### ❌ "Google Drive module not detecting new files"

**Causa:** Folder ID inválido ou nenhum arquivo foi adicionado

**Solução:**

1. **Verificar se pasta existe e tem arquivo:**
   - Acesse Google Drive
   - Abra pasta `/Novos_Produtos/`
   - Certifique-se de que há arquivo .jpg ou .png

2. **Verificar permissão:**
   - Pasta deve estar compartilhada com usuário do Make
   - Ou público de leitura
   - Verificar configuração de compartilhamento

3. **Testar trigger manualmente:**
   - No Make, ir em Módulo 1
   - Clicar em "Test Trigger"
   - Selecionar arquivo manualmente
   - Se funciona, problema é na detecção automática

4. **Aumentar frequência de polling:**
   - Se "Check every 5 minutes", aumentar para "every 1 minute"
   - (Pode usar mais operações do Make, aumentar custo)

5. **Verificar formatos:**
   - Arquivo deve ser imagem (JPEG, PNG)
   - Se arquivo é .JPG ou .jpg, ambos devem funcionar

---

## Problemas com IAs

### ❌ "Gemini: Image not recognized"

**Causa:** Imagem não contém produto claro

**Solução:**

1. **Usar imagem melhor:**
   - Imagem deve ser de produto CLARO
   - Fundo uniforme ajuda
   - Não foto de tela, não print do celular

2. **Adicionar descrição ao prompt:**
   ```
   Ao invés de:
   "Analise esta imagem"
   
   Usar:
   "Esta é uma imagem de um [CATEGORIA]. Extraia marca, modelo, EAN, etc"
   ```

3. **Aumentar modelo:**
   ```
   gemini-pro-vision → gemini-1.5-pro
   ```

---

### ❌ "Claude: Response too short or generic"

**Causa:** Prompt não deu contexto suficiente

**Solução:**

1. **Adicionar mais informações ao prompt:**
   ```
   Ao invés de:
   "Gere título"
   
   Usar:
   "Gere título otimizado para Mercado Livre focando em:
    - Palavras-chave de busca
    - Tamanho máximo 60 caracteres
    - Sem pontuação
    - Variações de cor importante"
   ```

2. **Dividir em 2 prompts:**
   - Um para copywriting geral
   - Outro para otimização específica por marketplace

---

### ❌ "OpenAI: Over quota"

**Erro:** "You exceeded your current quota"

**Causa:** Limite de uso DALL-E atingido

**Solução:**

1. **Verificar uso em OpenAI:**
   - https://platform.openai.com/account/usage
   - Ver quanto foi gasto em DALL-E

2. **Aumentar limite:**
   - https://platform.openai.com/account/billing/limits
   - Aumentar "Hard limit" ou "Soft limit"

3. **Economizar tokens:**
   - Reduzir número de testes DALL-E
   - Gerar 1 imagem por produto (não 3)
   - Agendar geração de imagem para 1x por dia

4. **Considerar modelo mais barato:**
   - dall-e-3 custa $0.080/imagem (1024x1024)
   - dall-e-2 custa $0.020/imagem (1024x1024)
   - Se não precisa muito realismo, usar dall-e-2

---

## Problemas de Publicação nos Marketplaces

### ❌ "Produto não aparece em Mercado Livre"

**Verificar ordem:**

1. **Em Tiny:**
   - [ ] SKU foi criado? (verificar em Produtos > Listar)
   - [ ] Campos customizados foram preenchidos? (abrir produto)

2. **Em Hub Olist:**
   - [ ] Webhook foi acionado? (verificar em Logs)
   - [ ] Houve erro de sincronização? (qual mensagem?)

3. **Em Mercado Livre:**
   - [ ] Produto está em "Rascunho"? (publicar manualmente)
   - [ ] Produto aparece em "Meus Produtos"? (filtrar por ativo)

**Se aparece em "Rascunho":**
- Hub publicou mas produto precisa aprovação
- Ir em Meus Produtos > Rascunho
- Clicar em "Publicar"

---

### ❌ "Imagem não aparece no marketplace"

**Verificar:**

1. **URL está acessível?**
   - Copiar URL de `url_bg_chat`
   - Abrir em navegador
   - Deve mostrar imagem (não 404)

2. **URL é HTTPS?**
   - Alguns marketplaces exigem HTTPS
   - HTTP pode não funcionar

3. **Formato correto?**
   - JPG/JPEG ✅
   - PNG ✅
   - WebP ✅
   - Outros formatos podem ter problemas

4. **Tamanho de arquivo:**
   - Muito grande (> 5MB): marketplace rejeita
   - Muito pequeno (< 50KB): qualidade ruim
   - Ideal: 200KB - 2MB

---

### ❌ "Produto criado mas estoque errado"

**Causa:** Estoque não sincronizou com Tiny

**Solução:**

1. **Verificar estoque em Tiny:**
   ```
   Produto > Editar > Estoque
   Deve estar: 1 (ou quantidade que colocou)
   ```

2. **Verificar webhook de sincronização:**
   ```
   Hub Olist > Webhooks
   Deve estar ativo: "Sincronizar estoque"
   ```

3. **Sincronizar manualmente:**
   ```
   Hub Olist > [Marketplace]
   Clique em: "Sincronizar agora" (se disponível)
   ```

---

## Problemas de Performance

### ❌ "Foto → Publicação demora > 30 minutos"

**Causa:** Alguma etapa do pipeline está lenta

**Verificar cada módulo:**

1. **Google Drive:** Deve ser < 1 minuto
   - Se > 5 min, aumentar frequência de polling em Make

2. **Gemini:** Deve ser < 1 minuto
   - Se > 3 min, problema é imagem ou conexão

3. **Claude:** Deve ser < 2 minutos
   - Se > 5 min, considerar modelo mais rápido

4. **DALL-E:** Deve ser < 2 minutos
   - Às vezes fica lenta, é esperado

5. **Tiny API:** Deve ser < 1 minuto
   - Se > 2 min, problema é conexão à API Tiny

6. **Hub Olist:** Deve ser < 5 minutos
   - Hub pode levar tempo para sincronizar
   - Normal até 15 minutos

7. **Marketplace:** Pode levar até 30 minutos
   - Mercado Livre: 10-15 min
   - Shopee: 5-10 min
   - Amazon: 15-30 min
   - TikTok: 5-15 min

**Se alguma etapa está lenta:**
- Verificar logs em Make
- Verificar se API está respondendo lentamente
- Tentar novamente (pode ter sido timeout momentâneo)

---

### ❌ "Make.com scenario uses too many operations"

**Causa:** Rodar cenário muitas vezes consome operações

**Solução:**

1. **Verificar plano Make:**
   - Free: 1000 operações/mês
   - Pro: 10000 operações/mês
   - Business: ilimitado

2. **Economizar operações:**
   - Reduzir frequência de polling (5 min → 30 min)
   - Não testar cenário muitas vezes
   - Remover módulos desnecessários
   - Fazer cada módulo 1x (não duplicar)

3. **Aumentar plano:**
   - Free para Pro: $9.99/mês
   - Pro para Business: $299/mês

---

## Debug Checklist

Quando algo falha, usar este checklist para debugar:

### 1️⃣ Verificar Logs

```bash
# Make.com
Abrir cenário > History > Ver último run
Clicar em cada módulo > Ver output/erro

# Tiny ERP
Logs de integração: ⚙️ > Logs/Webhooks

# Hub Olist
Histórico/Logs de sincronização
```

### 2️⃣ Testar Isolado

```bash
# Testar Tiny API diretamente
php scripts/test-automation-pipeline.php /caminho/imagem.jpg

# Testar validação
php scripts/validate-automation-setup.php

# Testar conexão Tiny
php -r "
  \$ch = curl_init('https://tiny.com.br/api/v3/contatos');
  curl_setopt(\$ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer TOKEN']);
  echo curl_exec(\$ch);
"
```

### 3️⃣ Verificar Dados Intermediários

```bash
# Imprimir saída de cada módulo no Make
Adicionar módulo "Logger" após cada etapa
Imprimir variáveis intermediárias
```

### 4️⃣ Testar Manualmente

```bash
# Criar produto manualmente no Tiny
# Verificar se Hub publica

# Upload manualmente em Google Drive
# Verificar se Make detecta

# Testar API em Postman
# Garantir que responde com sucesso
```

### 5️⃣ Documentar Problema

Se problema persiste:
- [ ] Screenshot do erro
- [ ] Logs completos (make + tiny + hub)
- [ ] Passos para reproduzir
- [ ] Tentativas de solução já feitas
- [ ] Abrir issue no GitHub ou contatar suporte

---

## 📞 Links de Suporte

- **Make.com Help:** https://www.make.com/help
- **Tiny ERP API Docs:** https://atendimento.tiny.com.br/hc/pt-br/articles
- **Hub Olist Docs:** https://help.olist.com.br/
- **Google Gemini:** https://ai.google.dev/
- **Claude API:** https://docs.anthropic.com/
- **OpenAI:** https://help.openai.com/

---

**Guia de Troubleshooting criado por:** Claude Code  
**Data:** 2026-07-09  
**Última atualização:** 2026-07-09  
**Status:** Pronto para uso
