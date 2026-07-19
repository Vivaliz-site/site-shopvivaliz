# Sincronizar 198 Produtos da Olist

## ⚠️ Importante
As credenciais corretas foram configuradas nos Secrets do GitHub:
- `OLIST_CLIENT_ID`
- `OLIST_CLIENT_SECRET`

## Fluxo de Sincronização

### Passo 1: Autorizar a Aplicação
Abra no navegador:
```
https://shopvivaliz.com.br/olist/connect.php
```

**O que acontece:**
- Você é redirecionado para o login da Olist/Tiny
- Faz login com: `atendimento@shopvivaliz.com.br`
- Autoriza a aplicação ShopVivaliz a acessar os produtos
- Olist redireciona de volta para `callback.php`

### Passo 2: Autorização Recebida
Você chegará em:
```
https://shopvivaliz.com.br/olist/callback.php
```

**O que ver:**
- Uma tela com um código de autorização
- Instruções para copiar o código
- Este código será usado para gerar um token de acesso

### Passo 3: Sincronizar Produtos
Após autorizar, acesse:
```
https://shopvivaliz.com.br/olist/sync-products.php
```

**O que acontece:**
- O sistema troca o código por um token de acesso válido
- Usa o token para buscar os 198 produtos da Olist
- Salva o cache em `logs/olist-products-cache.json`
- Retorna um JSON com o status da sincronização

**Exemplo de resposta:**
```json
{
  "sucesso": true,
  "total": 198,
  "com_imagem": 51,
  "sem_imagem": 147,
  "taxa_cobertura": 25.8,
  "cache_file": "/var/www/html/logs/olist-products-cache.json",
  "mensagem": "Sincronizacao concluida: 198 produtos, 51 com imagem"
}
```

## Arquivos Criados

### 1. `/olist/connect.php`
- Redireciona para o login OAuth da Olist
- Endpoint: https://id.olist.com/openid/authorize

### 2. `/olist/callback.php` (já existente)
- Recebe o código de autorização da Olist
- Mostra a interface para copiar o código

### 3. `/olist/sync-products.php` (novo)
- Troca o código por um token de acesso
- Busca todos os 198 produtos com paginação
- Analisa e conta produtos com/sem imagem
- Salva cache JSON para o catálogo usar

## Detalhes Técnicos

### OAuth Flow (authorization_code)

**1. Pedir Código:**
```
GET https://id.olist.com/openid/authorize?
  client_id=OLIST_CLIENT_ID&
  redirect_uri=https://shopvivaliz.com.br/olist/callback.php&
  response_type=code&
  scope=products:read
```

**2. Trocar Código por Token:**
```
POST https://id.olist.com/openid/token
  grant_type=authorization_code
  client_id=OLIST_CLIENT_ID
  client_secret=OLIST_CLIENT_SECRET
  code=CODIGO_RECEBIDO
  redirect_uri=https://shopvivaliz.com.br/olist/callback.php
```

**Resposta:**
```json
{
  "access_token": "eyJhbGciOiJSUzI1NiIs...",
  "token_type": "bearer",
  "expires_in": 3600,
  "refresh_token": "eyJhbGciOiJSUzI1NiIs..."
}
```

### Renovar Token (após 1 hora)

```
POST https://id.olist.com/openid/token
  grant_type=refresh_token
  client_id=OLIST_CLIENT_ID
  client_secret=OLIST_CLIENT_SECRET
  refresh_token=REFRESH_TOKEN_ANTERIOR
```

### Buscar Produtos

```
GET https://api.tiny.com.br/api/v2/produtos.json?
  limite=50&
  pagina=1&
  formato=json

Headers:
  Authorization: Bearer ACCESS_TOKEN
```

## Solução de Problemas

### Erro 403 Forbidden
- **Causa:** Token expirado ou sem permissão
- **Solução:** Refaça o login em `connect.php`

### Erro "Client not enabled"
- **Causa:** Aplicação sem permissão para OAuth
- **Solução:** Verifique se as permissões estão liberadas na Olist (já devem estar)

### Nenhum produto retorna
- **Causa:** Credenciais incorretas ou token inválido
- **Solução:** Verifique `OLIST_CLIENT_ID` e `OLIST_CLIENT_SECRET` no GitHub Secrets

## Fluxo de Integração (futura)

Após sincronizar e salvar o cache, o catálogo pode:
1. Ler `logs/olist-products-cache.json`
2. Exibir os 51 produtos com imagem
3. Marcar os 147 sem imagem para correção
4. Adicionar link para sincronizar novamente

## Links Rápidos

| Operação | URL |
|----------|-----|
| Conectar Olist | https://shopvivaliz.com.br/olist/connect.php |
| Callback Olist | https://shopvivaliz.com.br/olist/callback.php |
| Sincronizar | https://shopvivaliz.com.br/olist/sync-products.php |
| Cache | https://shopvivaliz.com.br/logs/olist-products-cache.json |

---

**Status:** ✅ Sistema configurado e pronto para sincronizar os 198 produtos da Olist
