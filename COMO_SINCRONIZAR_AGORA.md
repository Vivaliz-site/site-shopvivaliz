# 🔐 COMO SINCRONIZAR SECRETS AGORA

## Opção 1: Script Interativo (Recomendado)

### Passo 1: Execute o script

```bash
python sincronizar_secrets.py
```

### Passo 2: Siga as instruções

1. Script pedirá você acessar GitHub Secrets
2. Para cada secret, digite o nome EXATO ou pressione Enter
3. Script detectará discrepâncias
4. Script oferecerá 2 opções de correção

### Opção A: Suporte a Aliases (Recomendado)
- ✅ Código já suporta
- ✅ Funcionará com qualquer nome
- ✅ Apenas fazer: `git push origin main`

### Opção B: Renomear no GitHub
- ❌ Mais trabalho manual
- ❌ Necessário deletar e criar secrets

---

## Opção 2: Manual (Se preferir)

### Passo 1: Anote os nomes

Acesse: https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions

Anote os nomes EXATOS de cada secret existente.

### Passo 2: Identifique discrepâncias

Compare com os esperados:

```
ESPERADO              →  NO GITHUB (seu caso)
──────────────────────────────────────────
OPENAI_API_KEY       →  ?
FTP_SERVER           →  ?
FTP_USERNAME         →  ?
FTP_PASSWORD         →  ?
SHOPEE_PARTNER_ID    →  ?
SHOPEE_PARTNER_KEY   →  ?
TIKTOK_CLIENT_ID     →  ?
TIKTOK_CLIENT_SECRET →  ?
```

### Passo 3: Se nomes diferentes

**Opção A: Suporte a Aliases (Recomendado)**
```bash
git push origin main
```

Código já suporta aliases, funcionará direto.

**Opção B: Renomear no GitHub**
1. Delete cada secret com nome errado
2. Crie novo com nome esperado
3. Faça: `git push origin main`

---

## Status Atual

✅ Código pronto com suporte a aliases
✅ image_generator.py → múltiplos nomes para OPENAI
✅ upload_images.py → múltiplos nomes para FTP

✅ Documentação completa
✅ Scripts de verificação criados

---

## Próximo Passo Imediato

```bash
python sincronizar_secrets.py
```

Depois de executar, siga as instruções do script! 🚀
