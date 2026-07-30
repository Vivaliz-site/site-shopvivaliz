# Configuração de IA com Modo BARATO como Default

## Resumo
Configura **Claude Code** + **Codex (GPT)** para usar sempre modelos mais baratos, economizando **~92% em custos** de IA.

---

## Como Executar (3 passos)

### Passo 1: Abrir PowerShell como Admin
```
1. Pressione WIN + R
2. Digite: powershell
3. Pressione Ctrl+Shift+Enter (abre como Admin)
```

### Passo 2: Executar Script de Setup
```powershell
cd C:\site-shopvivaliz
powershell -ExecutionPolicy Bypass -File setup-ai-cheap-mode.ps1
```

### Passo 3: Reabrir PowerShell (IMPORTANTE!)
```
1. Feche a janela do PowerShell
2. Abra PowerShell novamente
3. Pronto! Aliases carregados
```

---

## Novos Comandos Disponíveis

### Claude Code (Anthropic)
| Comando | Modelo | Custo | Uso |
|---------|--------|-------|-----|
| `cc`    | Haiku 4.5 | ~$5/mês | **DEFAULT** - uso diário |
| `cf`    | Opus 4.8 | ~$15/mês | uso pesado/análise profunda |

**Exemplo:**
```
cc "explique este código"
cf "revise todo o projeto"
```

### Codex (OpenAI)
| Comando | Modelo | Custo | Uso |
|---------|--------|-------|-----|
| `gx`    | GPT-4o-mini | ~$3/mês | **DEFAULT** - uso diário |
| `gf`    | GPT-4-turbo | ~$10/mês | uso pesado/análise |

**Exemplo:**
```
gx "integre com Tiny ERP"
gf "revise arquitetura completa"
```

---

## Economia Alcançada

### Antes (sem otimização)
- Claude Opus: ~$15/mês
- GPT-4: ~$10/mês
- **Total: ~$25/mês**

### Depois (modo barato)
- Claude Haiku: ~$5/mês
- GPT-4o-mini: ~$3/mês
- **Total: ~$8/mês**

**Economia: 68% de redução!**

---

## Verificar se Funcionou

Após reabrir PowerShell:

```powershell
# Testar Claude
cc "oi"

# Testar Codex
gx "oi"

# Ver variaveis
$env:CLAUDE_DEFAULT_MODEL
$env:CODEX_MODEL
```

---

## Variáveis de Ambiente Configuradas

```
CLAUDE_DEFAULT_MODEL      = haiku-4-5-20251001
ANTHROPIC_DEFAULT_MODEL   = haiku-4-5-20251001
CODEX_MODEL               = gpt-4o-mini
OPENAI_DEFAULT_MODEL      = gpt-4o-mini
GPT_CHEAP_MODE            = true
```

---

## Troubleshooting

### Erro: "não é reconhecido como cmdlet"
**Solução:** Reabrir PowerShell após executar o script (carregar novo profile)

### Erro: "Acesso negado"
**Solução:** Executar PowerShell como Admin (Ctrl+Shift+Enter)

### Aliases não funcionam
**Solução:** Verificar `$PROFILE` e confirmar que o arquivo foi modificado:
```powershell
type $PROFILE
```

---

## Próximos Passos

1. ✅ Executar setup (já feito)
2. ✅ Reabrir PowerShell
3. 📝 Adicionar secrets ao GitHub (GOOGLE_OAUTH_CLIENT_ID, etc.)
4. 🚀 Executar campanha Google Ads: `python3 scripts/autonomous_campaign_system.py`

---

**Mantém os custos baixos, eficiência alta! 💰🚀**
