# Obter Google Ads Developer Token

## Passo 1: Acessar Google Ads Manager
1. Abra: https://ads.google.com/aw/overview
2. Clique em ⚙️ **Configurações** (canto superior direito)

## Passo 2: Ir para API Center
1. No menu lateral, selecione **API Center**
2. Se não vir, vá para: https://ads.google.com/aw/apicenter

## Passo 3: Criar ou Pegar Token
1. Procure por "**Developer token**"
2. Se já existe, copie o valor
3. Se não existe, clique "**Get access**"

## Passo 4: Adicionar ao GitHub Secrets
```bash
gh secret set GOOGLE_ADS_DEVELOPER_TOKEN --body "seu-token-aqui"
```

## Passo 5: Adicionar ao .env local
```bash
GOOGLE_ADS_DEVELOPER_TOKEN=seu-token-aqui
```

## Passo 6: Adicionar ao .env da VM
```bash
ssh -i "chave.key" ubuntu@137.131.156.17
echo "GOOGLE_ADS_DEVELOPER_TOKEN=seu-token-aqui" >> /home/ubuntu/site-shopvivaliz/.env
```

---

## Token Exemplo:
```
ca~XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
```

**Onde encontrar no painel:**
- Settings > API Center > Developer Token > Copy

**Status:** ⏳ AGUARDANDO OBTENÇÃO MANUAL
