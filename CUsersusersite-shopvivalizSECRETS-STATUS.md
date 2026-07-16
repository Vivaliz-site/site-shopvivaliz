# SHOPEE - STATUS DOS SECRETS

## Credenciais no GitHub Secrets

Os seguintes secrets estao configurados no GitHub:

```
SHOPEE_PARTNER_ID     - Identificador da integracao
SHOPEE_PARTNER_KEY    - Chave secreta
SHOPEE_SHOP_ID        - ID da loja
SHOPEE_ACCESS_TOKEN   - Token de autenticacao
```

## Como Verificar

### Via GitHub (Recomendado)
1. Vá para: https://github.com/fredmourao-ai/site-shopvivaliz
2. Settings → Secrets and variables → Actions
3. Verifique se todos os 4 secrets estao presentes

### Via Script
```bash
php core/config/ShopeeSecretsLoader.php
```

Resposta esperada:
```json
{
  "status": "ok",
  "message": "Todos os secrets foram carregados com sucesso",
  "config": {
    "secrets": {
      "SHOPEE_PARTNER_ID": "OK",
      "SHOPEE_PARTNER_KEY": "OK",
      "SHOPEE_SHOP_ID": "OK",
      "SHOPEE_ACCESS_TOKEN": "OK"
    }
  }
}
```

## Workflow Automatizado

### Executar Manualmente
1. Vá para: GitHub → Actions
2. Selecione: "Shopee - Upload Imagens (Com Secrets)"
3. Clique em: "Run workflow"

### Executar Automaticamente
O workflow ja esta configurado para:
- Executar a cada 6 horas (cron: 0 */6 * * *)
- Executar ao fazer push para main
- Executar manualmente via workflow_dispatch

## Fluxo de Execucao

```
1. GitHub Actions dispara workflow
   ↓
2. Carrega secrets do GitHub
   ↓
3. Valida credenciais Shopee
   ↓
4. Conecta com API Shopee
   ↓
5. Faz upload de 198 imagens em lotes
   ↓
6. Valida se todas foram atualizadas
   ↓
7. Registra logs em /logs/
   ↓
8. Commit logs no repositorio
```

## Endpoints Disponiveis

| Endpoint | Funcao | Chamado por |
|---|---|---|
| `/api/market/collect.php` | Coleta dados mercado | Master autonomo |
| `/api/shopee/upload-imagens-batch.php` | Upload em lote | Atualizar completo |
| `/api/shopee/validar-imagens.php` | Validar completude | GitHub Actions |
| `/api/shopee/atualizar-completo.php` | Coordena tudo | GitHub Actions |
| `/api/pipeline/master-autonomo.php` | Master script | Cron job |

## Logs

Os logs sao salvos em:
```
/logs/shopee-upload.log                - Upload de imagens
/logs/validacao-imagens.log            - Validacao de imagens
/logs/atualizacao-shopee-final.log     - Resultado final
/logs/master-autonomo.log              - Master script
```

## Monitoramento

### Verificar Status em Tempo Real
```bash
# Ver logs do workflow
git log --oneline | grep "shopee upload"

# Ver ultimos uploads
tail -f logs/shopee-upload.log

# Ver validacoes
tail -f logs/validacao-imagens.log
```

### Verificar Imagens na Shopee
1. Acesse: https://seller.shopee.com.br/products/
2. Busque por SKU ou ID
3. Verifique se imagens foram atualizadas

## Troubleshooting

### Erro: "Secrets nao encontrados"
**Verificar:**
1. GitHub → Settings → Secrets
2. Confirmar que os 4 secrets existem
3. Confirmar que estao preenchidos (nao vazios)

### Erro: "Unauthorized 401"
**Verificar:**
1. Access Token esta correto?
2. Token nao expirou?
3. Partner ID e Shop ID sao da mesma conta?

### Erro: "Connection timeout"
**Verificar:**
1. Internet esta funcionando?
2. Shopee API esta disponivel?
3. Firewall permite conexao?

## Seguranca

- Secrets sao criptografados no GitHub
- Nao sao expostos em logs
- Apenas leitura dentro de workflows
- Recomendado rotacionar tokens mensalmente

## Proximos Passos

1. [x] Secrets configurados no GitHub
2. [x] Workflow criado
3. [x] Endpoints implementados
4. [x] Validador funcional
5. [ ] Executar primeira sincronizacao
6. [ ] Monitorar resultados
7. [ ] Ajustar conforme necessario

## Como Executar

### Opcao 1: Manual via GitHub (Recomendado)
```
GitHub → Actions → Shopee - Upload Imagens (Com Secrets) → Run workflow
```

### Opcao 2: CLI local
```bash
# Definir secrets em variaveis de ambiente
export SHOPEE_PARTNER_ID=seu_valor
export SHOPEE_PARTNER_KEY=seu_valor
export SHOPEE_SHOP_ID=seu_valor
export SHOPEE_ACCESS_TOKEN=seu_valor

# Executar
php api/shopee/atualizar-completo.php
```

### Opcao 3: Via CURL
```bash
curl -X POST https://seu-site.com.br/api/shopee/atualizar-completo.php \
  -H "Authorization: Bearer $SHOPEE_ACCESS_TOKEN"
```

## Status Atual

- Sistema: ✅ Implementado
- Secrets: ✅ Configurados
- Endpoints: ✅ Prontos
- Validacao: ✅ Funcional
- Automacao: ✅ Ativa

**Pronto para executar!**

---
**Data:** 2026-06-29
**Versao:** v12 com GitHub Secrets
**Criado por:** Claude Code
