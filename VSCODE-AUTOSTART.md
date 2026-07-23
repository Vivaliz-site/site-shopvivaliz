# Autostart RCE Server no VS Code

## ✅ Opção 1: Usar Extensão `Run on Save` (Recomendado)

### Passo 1: Instalar Extensão
```bash
code --install-extension emeraldwalk.RunOnSave
```

### Passo 2: Já Configurado
O arquivo `.vscode/settings.json` já tem a configuração. O servidor vai iniciar automaticamente.

---

## ✅ Opção 2: Via Task do VS Code (Nativo)

### Passo 1: Abrir Command Palette
- `Ctrl + Shift + P` → "Tasks: Run Task"

### Passo 2: Selecionar
- Procure por `🔴 Start RCE Server`
- Clique e execute

### Passo 3: Automatizar
Para rodar ao abrir VS Code, use a extensão abaixo.

---

## ✅ Opção 3: Windows Task Scheduler (Mais Confiável)

### Passo 1: Abrir Task Scheduler
```powershell
taskschd.msc
```

### Passo 2: Criar Nova Tarefa
1. Clique em **"Create Task"** (lado direito)
2. **General:**
   - Nome: `Start RCE Server`
   - Descrição: `Inicia RCE Server quando usuário faz login`
   - Selecionar: "Run with highest privileges"

3. **Trigger:**
   - Clique em **New**
   - Begin the task: `At log on`
   - Clique OK

4. **Actions:**
   - Clique em **New**
   - Action: `Start a program`
   - Program: `powershell.exe`
   - Arguments:
     ```
     -NoProfile -ExecutionPolicy Bypass -File "c:\site-shopvivaliz\start-rce-bg.ps1"
     ```
   - Clique OK

5. **Conditions:**
   - Desmarcar tudo para sempre rodar

6. **Settings:**
   - Desmarcar "Stop task if it runs longer than"
   - Selecionar "Run task as soon as possible after a scheduled start is missed"

7. Clique **OK** e confirme credenciais

---

## ✅ Opção 4: Inicializar Manual (Teste Rápido)

### No Terminal do VS Code (Ctrl + `)

```powershell
.\start-rce-bg.ps1
```

---

## 🚀 Verificar se Está Rodando

### Via Task do VS Code
```
Ctrl + Shift + P → "Tasks: Run Task" → "Check RCE Server Status"
```

### Via PowerShell
```powershell
$token = 'hBu-3gs3meFOp82AnXLzljmIvNaf-7ih'
curl -X GET 'http://127.0.0.1:5557/status' `
  -H "Authorization: Bearer $token"
```

Deve retornar:
```json
{
  "status": "🔴 RCE ATIVO - SEM RESTRIÇÕES",
  "mode": "FULL_ACCESS",
  ...
}
```

---

## 🔴 Parar Servidor

### Via Task do VS Code
```
Ctrl + Shift + P → "Tasks: Run Task" → "Stop RCE Server"
```

### Via PowerShell
```powershell
Get-Process -Name node | Where-Object { $_.CommandLine -like '*rce-command-server*' } | Stop-Process -Force
```

---

## 📋 Arquivos Envolvidos

- `rce-command-server.js` — Servidor RCE
- `start-rce-bg.ps1` — Script que inicia em background
- `.vscode/tasks.json` — Tasks do VS Code
- `.vscode/launch.json` — Debug/Launch config
- `.vscode/settings.json` — Configurações (já modificado)

---

## 🔧 Troubleshooting

### Servidor não inicia automaticamente

**Causa:** Extensão não instalada ou não configurada  
**Solução:**

1. Instale `Run on Save`:
   ```bash
   code --install-extension emeraldwalk.RunOnSave
   ```

2. Reabra VS Code

3. Verifique em Extensions se está ativada

### "Access Denied" ao executar script

**Causa:** ExecutionPolicy do PowerShell  
**Solução:**
```powershell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser -Force
```

### Processo já está usando porta 5557

**Causa:** Outro servidor RCE já rodando  
**Solução:**
```powershell
Get-Process -Name node | Stop-Process -Force
```

Depois inicie novamente.

---

## ⚠️ Segurança

- Servidor inicia em `127.0.0.1:5557` (localhost apenas)
- Para acessar do iPhone, usar opção `-Ip` no script
- Token: `hBu-3gs3meFOp82AnXLzljmIvNaf-7ih` (mude depois!)

---

**Recomendação:** Use **Opção 3 (Task Scheduler)** para máxima confiabilidade e iniciar sempre que fizer login no Windows.
