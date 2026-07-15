# 📋 COMO OBTER OS NOMES EXATOS DOS SECRETS DO GITHUB

## Passo 1: Acesse GitHub Secrets

```
https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions
```

## Passo 2: Você verá uma tabela como:

```
NAME                      UPDATED_AT
─────────────────────────────────────────────────────
OPENAI_API_KEY            2024-06-29
FTP_HOST                  2024-06-29
FTP_USER                  2024-06-29
FTP_PASS                  2024-06-29
SHOPEE_ID                 2024-06-29
SHOPEE_KEY                2024-06-29
TIKTOK_ID                 2024-06-29
TIKTOK_SECRET             2024-06-29
EMAIL_FROM                2024-06-29
EMAIL_TO                  2024-06-29
EMAIL_USER                2024-06-29
EMAIL_PASS                2024-06-29
EMAIL_SMTP_HOST           2024-06-29
EMAIL_SMTP_PORT           2024-06-29
```

## Passo 3: Copie TODOS os nomes exatos (coluna NAME)

Exemplo: Se você vê `FTP_HOST` na tabela, copie `FTP_HOST` (não `FTP_SERVER`)

## Passo 4: Três opções

### Opção A: Forneça aqui via mensagem
Copie e cole os nomes dos secrets no GitHub aqui:

```
OPENAI_API_KEY→ (ou qual é o nome?)
FTP_SERVER→ (ou qual é o nome?)
FTP_USERNAME→ (ou qual é o nome?)
FTP_PASSWORD→ (ou qual é o nome?)
SHOPEE_PARTNER_ID→ (ou qual é o nome?)
SHOPEE_PARTNER_KEY→ (ou qual é o nome?)
TIKTOK_CLIENT_ID→ (ou qual é o nome?)
TIKTOK_CLIENT_SECRET→ (ou qual é o nome?)
```

Eu vou sincronizar o código automaticamente!

### Opção B: Execute o script interativo localmente
```bash
python sincronizar_secrets.py
```

Siga as instruções do script.

### Opção C: Apenas confirme que estão iguais
Se os nomes no GitHub são EXATAMENTE iguais aos esperados:
```
OPENAI_API_KEY
FTP_SERVER
FTP_USERNAME
FTP_PASSWORD
SHOPEE_PARTNER_ID
SHOPEE_PARTNER_KEY
TIKTOK_CLIENT_ID
TIKTOK_CLIENT_SECRET
EMAIL_FROM
EMAIL_TO
EMAIL_USER
EMAIL_PASSWORD
EMAIL_SMTP_HOST
EMAIL_SMTP_PORT
```

Apenas responda: "Sim, todos estão iguais" e faço git push!

---

## 🎯 Resumo:

1. **Opção A** = Você copia os nomes do GitHub aqui → Eu sincronizo automaticamente
2. **Opção B** = Você executa script interativo localmente → Script sincroniza
3. **Opção C** = Se estão iguais → Apenas confirme e faço git push

---

## ⚡ O que acontece em cada opção:

**Opção A (Mais rápido):**
- Você: Copia nomes do GitHub
- Eu: Ajusto código se necessário
- Resultado: Sistema pronto para rodar

**Opção B (Interativo):**
- Você: Executa script localmente
- Script: Faz tudo automaticamente
- Resultado: Sistema pronto para rodar

**Opção C (Se iguais):**
- Você: Apenas confirma nomes corretos
- Eu: Faço git push
- GitHub Actions: Começa automaticamente
- Resultado: Sistema roda 24/7

---

Qual opção você prefere? 🚀
