# GitHub Secrets Configuration

## Como Adicionar Secrets no GitHub

1. **Abra o repositório:**
   https://github.com/Vivaliz-site/site-shopvivaliz

2. **Vá para Settings → Secrets and variables → Actions**

3. **Clique em "New repository secret"** e adicione CADA:

### Secret 1: GOOGLE_OAUTH_CLIENT_ID
```
m71jvyuls7c4die88db14nv3bllmth0i.app.s.googleusercontent.com
```

### Secret 2: GOOGLE_OAUTH_CLIENT_SECRET
```
<novo_secret_rotacionado_no_google_cloud>
```

### Secret 3: GOOGLE_ADS_CUSTOMER_ID
```
5104079137
```

### Secret 4: GOOGLE_ADS_DEVELOPER_TOKEN
```
<obter_de_https://ads.google.com/aw/apicenter>
```

### Secret 5: GOOGLE_ADS_REFRESH_TOKEN
```
<gerar_via_oauth_google_ads>
```

### Secret 6: GOOGLE_ADS_ID
```
<conversion_id_ou_AW-id>
```

### Secret 7: GOOGLE_ADS_CONVERSION_LABEL
```
<conversion_label>
```

### Secret 8: GOOGLE_ANALYTICS_ID
```
<obter_do_google_analytics>
```

---

## Validacao obrigatoria

Depois de criar os secrets e sincronizar o ambiente, rode:

```bash
python3 scripts/google_ads_real_readiness.py
```

Resultado aceito:

```text
READY_FOR_REAL_GOOGLE_ADS_CREATE_PAUSED
```

Qualquer `NOT_READY` ou exit code diferente de zero bloqueia campanha real.

## Seguranca

O secret antigo foi exposto neste arquivo. Rotacione `GOOGLE_OAUTH_CLIENT_SECRET` no Google Cloud antes de usar em producao.
