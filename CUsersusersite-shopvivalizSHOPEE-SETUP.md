# SHOPEE API - GUIA DE CONFIGURACAO

## STATUS ATUAL

Sistema implementado: SIM
Endpoints funcionando: SIM (modo simulado)
Conexao real Shopee: NAO (aguardando credenciais)

## PASSO 1: OBTER CREDENCIAIS SHOPEE

### Acesse Shopee Seller Center
1. Vá para https://seller.shopee.com.br/
2. Faça login com sua conta
3. Clique em: **Configuracoes** (canto superior direito)
4. Vá para: **Ferramenta** → **Open API**

### Copie as 4 Credenciais
Você vai precisar de:
- **Partner ID** (identificador da sua integracao)
- **Partner Key** (chave secreta)
- **Shop ID** (ID unico da sua loja)
- **Access Token** (token de autenticacao)

## PASSO 2: CONFIGURAR CREDENCIAIS

### Opcao A: Via Arquivo .env (Recomendado)

1. Copie o arquivo template:
   ```bash
   cp .env.example .env
   ```

2. Abra `.env` em um editor de texto

3. Preencha com suas credenciais:
   ```
   SHOPEE_PARTNER_ID=123456789
   SHOPEE_PARTNER_KEY=sua_chave_secreta_aqui
   SHOPEE_SHOP_ID=987654321
   SHOPEE_ACCESS_TOKEN=seu_token_aqui
   ```

4. Salve o arquivo

### Opcao B: Via Variaveis de Ambiente

Adicione ao seu servidor/sistema:
```bash
export SHOPEE_PARTNER_ID=123456789
export SHOPEE_PARTNER_KEY=sua_chave_secreta_aqui
export SHOPEE_SHOP_ID=987654321
export SHOPEE_ACCESS_TOKEN=seu_token_aqui
```

## PASSO 3: VALIDAR CONFIGURACAO

Execute:
```bash
php core/config/ShopeeConfig.php
```

Resposta esperada:
```json
{
  "status": "ok",
  "config": {
    "configurado": true,
    "credenciais": {
      "partner_id": "OK",
      "partner_key": "OK",
      "shop_id": "OK",
      "access_token": "OK"
    }
  }
}
```

## PASSO 4: TESTAR CONEXAO

Execute um teste basico:
```bash
curl -X POST https://partner.shopeemx.com/api/v2/product/get_shop_base \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json"
```

## PASSO 5: ATIVAR ATUALIZACAO DE IMAGENS

Uma vez configurado, execute:

### Via Web
```
GET https://seu-site.com.br/api/shopee/atualizar-completo.php
```

### Via CLI
```bash
php api/shopee/atualizar-completo.php
```

### Via Cron (Automatico)
Adicione ao seu crontab:
```
0 6 * * * curl https://seu-site.com.br/api/shopee/atualizar-completo.php
```

## TROUBLESHOOTING

### Erro: ".env file not found"
**Solucao:**
```bash
cp .env.example .env
```

### Erro: "Credencial nao configurada"
**Verificar:**
1. Se .env existe
2. Se tem valores reais (nao "seu_partner_id_aqui")
3. Se nao tem comentarios (#) na linha

### Erro: "Unauthorized" ou "401"
**Verificar:**
1. Se Access Token esta correto
2. Se Partner Key esta correto
3. Se Token nao expirou (gerar novo se necessario)

### Erro: "Shop not found"
**Verificar:**
1. Se Shop ID e Partner ID correspondem mesma conta
2. Se loja esta ativa na Shopee

## SEGURANCA

IMPORTANTE:
- Nunca commit .env no Git
- Adicione `.env` ao .gitignore
- Nao compartilhe credenciais
- Rotate tokens regularmente
- Use HTTPS sempre

## MONITORAMENTO

### Verificar Status
```bash
php core/config/ShopeeConfig.php
```

### Ver Logs de Upload
```bash
tail -f logs/shopee-upload.log
tail -f logs/validacao-imagens.log
tail -f logs/atualizacao-shopee-final.log
```

### Verificar Produtos na Shopee
1. Vá para: https://seller.shopee.com.br/products/
2. Busque por SKU ou ID
3. Verifique se imagens foram atualizadas

## CRONOGRAMA DE EXECUCAO

### Automatico (5 em 5 minutos)
```
GitHub Actions: .github/workflows/v12-execucao-automatica.yml
```

### Diario (06:00 UTC)
```
GitHub Actions: Cron job programado
```

### Manual
```bash
curl https://seu-site.com.br/api/shopee/atualizar-completo.php
```

## ENDPOINTS DISPONIVEIS

| Endpoint | Descricao |
|---|---|
| `/api/market/collect.php` | Coleta dados mercado |
| `/api/shopee/upload-imagens-batch.php` | Upload de imagens |
| `/api/shopee/validar-imagens.php` | Validar completude |
| `/api/shopee/atualizar-completo.php` | Atualizar tudo |
| `/api/pipeline/master-autonomo.php` | Master script |

## PROXIMOS PASSOS

1. [ ] Copiar .env.example para .env
2. [ ] Preencher credenciais Shopee
3. [ ] Executar: `php core/config/ShopeeConfig.php`
4. [ ] Testar conexao
5. [ ] Executar: `php api/shopee/atualizar-completo.php`
6. [ ] Verificar imagens na Shopee
7. [ ] Ativar automacao (cron/GitHub Actions)

## SUPORTE

Se encontrar problemas:
1. Verifique .env esta preenchido
2. Valide credenciais Shopee (sao corretas?)
3. Teste conexao direta via curl
4. Verifique logs em /logs/
5. Acione suporte Shopee (Open API)

---

**Data:** 2026-06-29
**Versao:** v12 Autonomo
**Status:** Pronto para producao
