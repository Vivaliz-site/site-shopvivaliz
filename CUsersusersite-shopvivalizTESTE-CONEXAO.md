# TESTE DE CONEXAO SHOPEE

## Status Atual

```
Secrets no GitHub: CONFIGURADOS
Endpoints: PRONTOS
Script de teste: CRIADO
```

## Como Testar

### Opcao 1: Testar no GitHub Actions (RECOMENDADO)

O teste de conexao sera executado automaticamente quando o workflow rodar.

1. Vá para: https://github.com/fredmourao-ai/site-shopvivaliz
2. Clique em: **Actions**
3. Selecione: **Shopee - Upload Imagens (Com Secrets)**
4. Clique em: **Run workflow** → **Run workflow**
5. Aguarde a execucao

**Resultado esperado:**
```
[TESTE 1] Verificando GitHub Secrets...
  SHOPEE_PARTNER_ID: OK
  SHOPEE_PARTNER_KEY: OK
  SHOPEE_SHOP_ID: OK
  SHOPEE_ACCESS_TOKEN: OK
  Total: 4/4

[TESTE 2] Validando formato das credenciais...
  Total: 4/4

[TESTE 3] Testando conexao com Shopee API...
  [RESPONSE] HTTP 200
  [SUCESSO] Conexao estabelecida

STATUS: CONECTADO E PRONTO
```

### Opcao 2: Testar Localmente

Se quiser testar localmente com os secrets:

```bash
# 1. Exporte os secrets do GitHub Secrets
# (Copie-os manualmente de: https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions)

export SHOPEE_PARTNER_ID="seu_partner_id"
export SHOPEE_PARTNER_KEY="sua_partner_key"
export SHOPEE_SHOP_ID="seu_shop_id"
export SHOPEE_ACCESS_TOKEN="seu_access_token"

# 2. Execute o teste
php scripts/test-shopee-connection.php
```

### Opcao 3: Testar via Script Bash

```bash
# 1. Exporte os secrets
export SHOPEE_PARTNER_ID="seu_partner_id"
export SHOPEE_PARTNER_KEY="sua_partner_key"
export SHOPEE_SHOP_ID="seu_shop_id"
export SHOPEE_ACCESS_TOKEN="seu_access_token"

# 2. Execute o script de teste
bash scripts/test-local.sh
```

## O Que o Teste Faz

### Teste 1: Verificar Secrets
- Confirma se todos os 4 secrets estao definidos
- Valida se nao estao vazios

### Teste 2: Validar Formato
- Valida se Partner ID eh numerico
- Valida se Partner Key tem tamanho correto
- Valida se Shop ID eh numerico
- Valida se Access Token tem tamanho correto

### Teste 3: Conectar com Shopee API
- Faz requisicao POST para: /api/v2/product/get_shop_base
- Aguarda resposta HTTP 200
- Confirma se consegue se conectar

### Teste 4: Testar Upload
- Confirma se endpoints de upload estao prontos
- Valida capacidade de 198 produtos

## Resultado dos Testes

### Se PASSAR:
```
STATUS: CONECTADO E PRONTO

PROXIMAS ACOES:
  1. Executar workflow no GitHub
  2. Monitorar upload de 198 imagens
  3. Validar completude
```

### Se FALHAR (Credenciais):
```
Secrets incompletos
Nao e possivel testar sem credenciais

SOLUCAO:
  1. Verificar GitHub Secrets
  2. Confirmar que todos os 4 estao preenchidos
  3. Testar novamente
```

### Se FALHAR (Conexao):
```
Credenciais OK, mas conexao falhou

POSSIVEL MOTIVO:
  - Access Token expirou (gerar novo)
  - Partner ID ou Shop ID incorreto
  - Shopee API indisponivel
  - Firewall bloqueando conexao

SOLUCAO:
  1. Verificar se Token ainda eh valido
  2. Testar com curl manualmente:
     curl -X POST https://partner.shopeemx.com/api/v2/product/get_shop_base \
       -H "Authorization: Bearer SEU_TOKEN"
  3. Contactar suporte Shopee se problema persistir
```

## Arquivos de Teste

- `scripts/test-shopee-connection.php` - Script PHP de teste completo
- `scripts/test-local.sh` - Script bash para testar localmente
- `core/config/ShopeeSecretsLoader.php` - Carregador de secrets

## Status Atual

```
[2026-06-29 08:00:00]

Sistema: PRONTO PARA TESTAR
Secrets: CONFIGURADOS NO GITHUB
Endpoints: IMPLEMENTADOS
Script de teste: CRIADO

PROXIMA ETAPA: Executar teste no GitHub Actions
```

## Checklist de Teste

- [ ] Verificar se os 4 secrets existem no GitHub
- [ ] Executar workflow "Shopee - Upload Imagens (Com Secrets)"
- [ ] Aguardar conclusao do workflow
- [ ] Verificar se teste passou (STATUS: CONECTADO)
- [ ] Verificar upload de 198 imagens
- [ ] Validar imagens na Shopee
- [ ] Confirmar automatizacao (próxima execucao em 6h)

## Troubleshooting

### "Secrets nao encontrados"
1. GitHub → Settings → Secrets and variables → Actions
2. Confirme que os 4 secrets existem
3. Confirme que estao preenchidos

### "HTTP 401 Unauthorized"
1. Access Token esta correto?
2. Token nao expirou?
3. Testar com curl manualmente

### "HTTP 403 Forbidden"
1. Partner ID corresponde ao Token?
2. Shop ID esta correto?
3. Conta tem permissoes de API?

### "Connection timeout"
1. Internet esta funcionando?
2. Firewall permite https://partner.shopeemx.com?
3. Shopee API esta online?

## Proximos Passos

1. [ ] Executar teste no GitHub Actions
2. [ ] Confirmar conexao com sucesso
3. [ ] Rodar upload de 198 imagens
4. [ ] Validar imagens na Shopee
5. [ ] Ativar automacao (6 em 6 horas)

---
**Data:** 2026-06-29
**Versao:** v12 - Teste de Conexao
**Status:** Pronto para testar
