# 🔄 Setup de Sincronização Automática

## ⚡ Quick Start (2 Passos)

### Passo 1: Abrir PowerShell como Administrador

```powershell
# Abrir: Start Menu → Digite "PowerShell"
# Clique com botão direito → "Executar como Administrador"
```

### Passo 2: Executar Setup

```powershell
cd c:\site-shopvivaliz
.\scripts\setup_auto_sync.ps1
```

**Saída esperada:**
```
✅ Executando como Administrador
🚀 ShopVivaliz - Setup de Auto Sync
📝 Criando tarefa de sincronização...
✅ Tarefa criada com sucesso!
🧪 Executando teste...
✅ Sincronização concluída com sucesso!
```

---

## 🎯 O Que Acontece

Após o setup, a sincronização automática:

1. **A cada 5 minutos** (configurável):
   - ✅ Faz `git pull` (atualiza com mudanças remotas)
   - ✅ Faz `git add && git commit` (se houver mudanças locais)
   - ✅ Faz `git push` (envia mudanças para GitHub)
   - ✅ Valida secrets
   - ✅ Registra tudo em `logs/auto-sync-*.log`

2. **Roda em background** (sem interrupções)

3. **Recupera de erros** (tenta novamente no próximo intervalo)

---

## 🛠️ Comandos Úteis

### Ver Status

```powershell
.\scripts\setup_auto_sync.ps1 -Status
```

### Alterar Intervalo (ex: 15 minutos)

```powershell
.\scripts\setup_auto_sync.ps1 -Interval 15
```

### Ver Logs

```powershell
# Último log (hoje)
Get-Content logs/auto-sync-$(Get-Date -Format 'yyyy-MM-dd').log -Tail 50

# Monitorar em tempo real
Get-Content -Path logs/auto-sync-*.log -Wait -Tail 20
```

### Desabilitar Sincronização

```powershell
.\scripts\setup_auto_sync.ps1 -Remove
```

---

## 📋 Comparação: Antes vs Depois

| Aspecto | Antes | Depois |
|---------|-------|--------|
| **Manual** | Push/Pull manual | Automático a cada N min |
| **Conflitos** | Não detecta | Detecta e tenta resolver |
| **Validação** | Manual | Automática |
| **Logs** | Nenhum | Completos em `logs/` |
| **Esforço** | Alto | Zero |

---

## 🔧 Troubleshooting

### Problema: "Acesso Negado"

```
❌ Este script requer permissões de Administrador!
```

**Solução:**
1. Abrir PowerShell como Administrador
2. Rodar comando novamente

### Problema: "Script não encontrado"

```
❌ Script não encontrado: C:\path\auto_sync_git.ps1
```

**Solução:**
1. Verificar que está no diretório correto
2. Verificar que `scripts/auto_sync_git.ps1` existe

### Problema: Tarefa não executa

**Solução:**
1. Ver status: `.\scripts\setup_auto_sync.ps1 -Status`
2. Verificar logs: `Get-Content logs/auto-sync-*.log -Tail 50`
3. Remover e recriar: `.\scripts\setup_auto_sync.ps1 -Remove` + novamente

### Problema: Conflitos Git

Se houver conflitos, o script:
1. Tenta fazer pull
2. Se falhar, registra em logs
3. Tenta novamente no próximo intervalo

**Solução manual:**
```bash
cd c:\site-shopvivaliz
git status  # Ver o que está acontecendo
git pull    # Tentar resolver
```

---

## 📊 Monitoramento

### Ver últimas sincronizações

```powershell
$LogFile = "logs/auto-sync-$(Get-Date -Format 'yyyy-MM-dd').log"
Get-Content $LogFile | Select-String "✅|❌" | Select-Object -Last 20
```

### Gráfico de atividade (PowerShell)

```powershell
$LogFile = "logs/auto-sync-$(Get-Date -Format 'yyyy-MM-dd').log"
(Get-Content $LogFile | Measure-Object -Line).Lines
```

### Verificar Git status

```powershell
git status
git log --oneline | head -10
```

---

## 🔐 Segurança

### O Script NÃO

- ❌ Faz força push (--force)
- ❌ Deleta branches
- ❌ Reseta hard commits
- ❌ Modifica arquivos diretamente

### O Script SIM

- ✅ Faz pull seguro
- ✅ Faz commit apenas se tiver mudanças
- ✅ Faz push normal (não force)
- ✅ Registra tudo em logs
- ✅ Para em erros (não sobreescreve)

---

## 🎯 Próximas Ações

Após ativar auto-sync:

1. ✅ Monitorar logs por 10 minutos
2. ✅ Fazer uma mudança local e ver se sincroniza
3. ✅ Fazer uma mudança no GitHub e ver se puxa
4. ✅ Deixar rodando indefinidamente

---

## 📚 Arquivos Relacionados

- `scripts/auto_sync_git.ps1` - Script principal
- `scripts/setup_auto_sync.ps1` - Configurador
- `logs/auto-sync-*.log` - Logs de execução
- `CLAUDE.md` - Documentação do sistema

---

**Status: ✅ Pronto para Sincronização Automática**

Tempo de setup: 2 minutos ⚡
