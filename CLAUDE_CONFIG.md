# Configuração de Claude Code - Terminal Default

**Objetivo:** Abrir Claude Code/Codex com Haiku 4.5 (mais barato) como modelo padrão

---

## 1️⃣ CONFIGURAR VARIÁVEL DE AMBIENTE (Global)

### Windows PowerShell:

```powershell
# Adicionar ao $PROFILE (executar como Admin)
# Abrir: $PROFILE

[System.Environment]::SetEnvironmentVariable("CLAUDE_DEFAULT_MODEL", "haiku-4-5-20251001", "User")

# Ou manualmente em Settings → Environment Variables
# Variável: CLAUDE_DEFAULT_MODEL
# Valor: haiku-4-5-20251001
```

### Linux/Mac (Bash/Zsh):

```bash
# Adicionar ao ~/.bashrc ou ~/.zshrc

export CLAUDE_DEFAULT_MODEL="haiku-4-5-20251001"
export ANTHROPIC_DEFAULT_MODEL="haiku-4-5-20251001"
```

---

## 2️⃣ CRIAR ALIAS NO TERMINAL

### PowerShell:

```powershell
# Adicionar ao seu $PROFILE

function claude-cheap {
    $env:CLAUDE_DEFAULT_MODEL="haiku-4-5-20251001"
    claude @args
}

Set-Alias -Name cc -Value claude-cheap -Scope Global
```

**Uso:**
```powershell
cc  # Abre Claude Code com Haiku 4.5
cc code-review  # Abre com subcomando
```

### Bash/Zsh:

```bash
# Adicionar ao ~/.bashrc ou ~/.zshrc

alias cc='CLAUDE_DEFAULT_MODEL=haiku-4-5-20251001 claude'
alias claude-cheap='CLAUDE_DEFAULT_MODEL=haiku-4-5-20251001 claude'
```

**Uso:**
```bash
cc  # Abre com Haiku 4.5
claude-cheap code-review  # Com subcomando
```

---

## 3️⃣ ARQUIVO DE CONFIGURAÇÃO (.claude.toml)

Criar em: `~/.claude/config.toml` (ou `C:\Users\{user}\.claude\config.toml`)

```toml
[defaults]
model = "haiku-4-5-20251001"
max_tokens = 4096

[claude-code]
default_model = "haiku-4-5-20251001"
terminal_model = "haiku-4-5-20251001"

[performance]
prefer_cheaper_models = true
auto_select_optimal_model = false
```

---

## 4️⃣ SCRIPT DE INICIALIZAÇÃO AUTOMÁTICA

### Windows (PowerShell Profile):

**Caminho:** `$PROFILE`

```powershell
# ╔════════════════════════════════════════╗
# ║ ShopVivaliz Claude Code Configuration  ║
# ╚════════════════════════════════════════╝

# Default Model: Haiku 4.5 (Cheapest)
$env:CLAUDE_DEFAULT_MODEL = "haiku-4-5-20251001"
$env:ANTHROPIC_DEFAULT_MODEL = "haiku-4-5-20251001"

# Aliases
function claude-cheap {
    Write-Host "🚀 Claude Code - Haiku 4.5 (Cheapest Model)" -ForegroundColor Green
    $env:CLAUDE_DEFAULT_MODEL = "haiku-4-5-20251001"
    claude @args
}

function claude-fast {
    Write-Host "⚡ Claude Code - Opus 4.8 (Faster)" -ForegroundColor Yellow
    $env:CLAUDE_DEFAULT_MODEL = "claude-opus-4-8-20250805"
    claude @args
}

Set-Alias -Name cc -Value claude-cheap -Force
Set-Alias -Name cf -Value claude-fast -Force

# Welcome message
Write-Host "✅ Claude Code configured: cc=Haiku (cheap), cf=Opus (fast)" -ForegroundColor Cyan
```

### Linux/Mac (Bash/Zsh):

**Adicionar ao ~/.bashrc ou ~/.zshrc:**

```bash
# ╔════════════════════════════════════════╗
# ║ ShopVivaliz Claude Code Configuration  ║
# ╚════════════════════════════════════════╝

export CLAUDE_DEFAULT_MODEL="haiku-4-5-20251001"
export ANTHROPIC_DEFAULT_MODEL="haiku-4-5-20251001"

# Aliases
alias cc='CLAUDE_DEFAULT_MODEL=haiku-4-5-20251001 claude'
alias cf='CLAUDE_DEFAULT_MODEL=claude-opus-4-8-20250805 claude'

echo "✅ Claude configured: cc=Haiku (cheap), cf=Opus (fast)"
```

---

## 5️⃣ VERIFICAR CONFIGURAÇÃO

```bash
# Confirmar modelo padrão
echo $CLAUDE_DEFAULT_MODEL

# Ou via Claude:
claude --version
claude --info
```

---

## 📊 MODELOS DISPONÍVEIS

| Alias | Modelo | Custo | Velocidade | Uso |
|-------|--------|-------|-----------|-----|
| `cc` | haiku-4-5 | 💰 Mais barato | ⚡ Rápido | Daily |
| `cf` | opus-4-8 | 💰💰💰 Caro | ⚡⚡⚡ Mais rápido | Complex |
| `claude` | Default session | - | - | Híbrido |

---

## ✅ RESULTADO ESPERADO

Após configurar:

```powershell
PS> cc
🚀 Claude Code - Haiku 4.5 (Cheapest Model)
[Claude abre com Haiku 4.5]

PS> cf
⚡ Claude Code - Opus 4.8 (Faster)
[Claude abre com Opus 4.8]
```

---

## 🔄 APLICAR CONFIGURAÇÃO

**Windows:**
1. Abrir PowerShell como Admin
2. `code $PROFILE`
3. Copiar o script acima
4. Salvar e fechar
5. Fechar e reabrir PowerShell
6. Testar: `cc`

**Linux/Mac:**
1. `nano ~/.bashrc` (ou ~/.zshrc)
2. Colar script no final
3. `Ctrl+O` → `Enter` → `Ctrl+X`
4. `source ~/.bashrc`
5. Testar: `cc`

