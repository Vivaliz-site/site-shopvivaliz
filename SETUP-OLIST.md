# Configuração Olist/TinyERP - ShopVivaliz

## Problema Atual
- Catálogo mostra apenas 6 produtos de teste
- Deveria mostrar 198 do Olist
- Causa: TINY_ERP_API_KEY não configurada no servidor

## Solução

### Opção 1: Via .env (Local)
```bash
# Criar arquivo .env na raiz do projeto
TINY_ERP_API_KEY=sua_chave_tinyerp_aqui
```

### Opção 2: Via Painel de Hosting (Produção)
1. Acesse seu painel de hosting
2. Vá em "Variáveis de Ambiente" ou "Environment Variables"
3. Adicione:
   - Nome: `TINY_ERP_API_KEY`
   - Valor: sua chave do TinyERP

### Opção 3: Via GitHub Actions (Deploy)
1. Acesse: https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets
2. Crie novo secret:
   - Name: `TINY_ERP_API_KEY`
   - Value: sua chave

## Obtendo a Chave
1. Acesse: https://www.tiny.com.br/
2. Faça login
3. Vá em Configurações → Integrações
4. Procure por "Token de acesso" ou "API Key"
5. Copie a chave

## Testando
Após configurar, acesse:
- https://shopvivaliz.com.br/catalogo/
- Deverá carregar 198 produtos
- Haverá filtros por categoria
- Cache funcionará (1 hora)

## Se ainda não funcionar
- Limpar cache: `rm logs/olist-products-cache.json`
- Verificar logs: `tail -50 logs/monitor-responses.jsonl`
- Revisar: `catalogo/index.php` linhas 7-10 (config Olist)
