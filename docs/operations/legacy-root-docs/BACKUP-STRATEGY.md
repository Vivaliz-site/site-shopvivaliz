# 🔒 ESTRATÉGIA DE BACKUP - ShopVivaliz

**Efetivo:** 2026-07-24  
**Escopo:** Repositório completo + histórico  
**Objetivo:** Recuperação rápida em caso de disaster/corrupção

---

## 📊 Resumo Executivo

| Aspecto | Configuração |
|--------|--------------|
| **Frequência** | Diária (02:00 AM) |
| **Retenção** | Mínimo 10 dias |
| **Armazenamento** | `C:\backups\site-shopvivaliz\` |
| **Compressor** | 7-Zip (se instalado) / ZIP fallback |
| **Exclusões** | `.git`, `.vscode`, `node_modules`, `.claude`, `logs` |
| **Logs** | `C:\backups\site-shopvivaliz\logs\` |
| **Safeguard** | Backup pré-restauração automático |
| **RPO** | 24 horas (Recovery Point Objective) |
| **RTO** | < 5 minutos (Recovery Time Objective) |

---

## 🚀 INÍCIO RÁPIDO

### Instalação do Agendador (One-time)

```powershell
# Abrir PowerShell como Administrador
# Navegar para: C:\Users\FRED\site-shopvivaliz

cd C:\Users\FRED\site-shopvivaliz
.\scripts\install-backup-scheduler.ps1

# Opcional: especificar horário diferente
.\scripts\install-backup-scheduler.ps1 -Time "03:00"
```

**✅ Resultado:** Tarefa agendada `ShopVivaliz-DailyBackup` criada.

### Executar Backup Manualmente

```powershell
cd C:\Users\FRED\site-shopvivaliz
.\scripts\backup-daily.ps1

# Com diretório/retenção customizado:
.\scripts\backup-daily.ps1 -BackupDir "E:\backups" -RetentionDays 14
```

### Restaurar Repositório

```powershell
# Listar backups disponíveis
cd C:\Users\FRED\site-shopvivaliz
.\scripts\restore-backup.ps1

# Restaurar backup específico
.\scripts\restore-backup.ps1 -BackupFile "C:\backups\site-shopvivaliz\site-shopvivaliz-2026-07-24.7z"

# Restaurar sem confirmação interativa
.\scripts\restore-backup.ps1 -BackupFile "C:\backups\site-shopvivaliz\site-shopvivaliz-2026-07-24.7z" -Force
```

---

## 📁 Estrutura de Diretórios

```
C:\backups\site-shopvivaliz\
├── site-shopvivaliz-2026-07-24.7z       (Backup diário)
├── site-shopvivaliz-2026-07-23.7z
├── site-shopvivaliz-2026-07-22.7z
├── site-shopvivaliz-2026-07-21.7z
├── site-shopvivaliz-2026-07-20.7z
├── site-shopvivaliz-2026-07-19.7z
├── site-shopvivaliz-2026-07-18.7z
├── site-shopvivaliz-2026-07-17.7z
├── site-shopvivaliz-2026-07-16.7z
├── site-shopvivaliz-2026-07-15.7z       (Mais antigo mantido)
├── safeguard/
│   └── pre-restore-2026-07-24_14-30-15.7z  (Backup pré-restauração)
└── logs/
    ├── backup-2026-07-24_02-00-15.log
    ├── backup-2026-07-23_02-00-12.log
    ├── restore-2026-07-24_14-30-15.log
    └── ...
```

---

## 🔄 Como Funciona

### 1. Execução Automática Diária

**Agendador Windows Task Scheduler:**
- ✅ Tarefa: `ShopVivaliz-DailyBackup`
- ✅ Horário: 02:00 AM (personalizável)
- ✅ Frequência: Diária
- ✅ Usuário: Seu usuário Windows (admin)

**Processo:**
```
02:00 AM
  ↓
[Task Scheduler dispara]
  ↓
[\scripts\backup-daily.ps1 executado]
  ↓
[Repositório comprimido → site-shopvivaliz-YYYY-MM-DD.7z]
  ↓
[Backups antigos (>10 dias) deletados automaticamente]
  ↓
[Log salvo em logs/backup-TIMESTAMP.log]
  ↓
Pronto para recuperação
```

### 2. Compressão Otimizada

**Se 7-Zip instalado:**
- ✅ Formato: `.7z` (melhor compressão)
- ✅ Nível: `-mx=9` (máximo)
- ✅ Tamanho típico: 80-150 MB (dependendo de conteúdo)

**Fallback (sem 7-Zip):**
- ⚠️ Formato: `.zip` (Windows built-in)
- ⚠️ Compressão: Padrão
- ⚠️ Tamanho: ~2x maior que 7z

**Recomendação:** Instale 7-Zip para melhor compressão
- https://www.7-zip.org/download.html

### 3. Rotação Automática

**Política de retenção:**
- ✅ Mantém no mínimo: **10 dias** de backups
- ✅ Deleta: Backups com >10 dias
- ✅ Frequência de verificação: A cada backup (diário)

**Exemplo:**
```
Hoje: 2026-07-24 02:00
Limiar de exclusão: 2026-07-14 (10 dias atrás)

Mantém:
  ✅ 2026-07-24 (hoje)
  ✅ 2026-07-23 (1 dia)
  ✅ 2026-07-22 (2 dias)
  ✅ ...
  ✅ 2026-07-15 (9 dias)

Deleta:
  ❌ 2026-07-14 (10 dias exatos = fora do período)
  ❌ 2026-07-13 (mais antigo)
```

### 4. Safeguard Pré-Restauração

**Quando você restaura um backup:**

```
Você executa: restore-backup.ps1 -BackupFile "..."
  ↓
[Script detecta que vai SUBSTITUIR repositório]
  ↓
[Cria backup do estado ATUAL em safeguard/]
  ↓
[Depois restaura o backup solicitado]
  ↓
Se deu ruim → pode restaurar do safeguard automaticamente
```

**Benefício:** Proteção contra restaurações erradas

---

## 📋 Checklist de Operação

### Depois da Instalação

- [ ] Executar agendador: `.\scripts\install-backup-scheduler.ps1`
- [ ] Verificar em Task Scheduler: `ShopVivaliz-DailyBackup`
- [ ] Instalar 7-Zip (recomendado): https://www.7-zip.org/
- [ ] Testar backup manual: `.\scripts\backup-daily.ps1`
- [ ] Verificar arquivo criado: `C:\backups\site-shopvivaliz\`

### Monitoramento Semanal

- [ ] Verificar se `C:\backups\site-shopvivaliz\` tem backups recentes
- [ ] Verificar espaço em disco (mínimo 500 MB livre)
- [ ] Revisar logs em `logs/` (nenhum erro?)
- [ ] Teste de restauração uma vez por mês

### Manutenção Trimestral

- [ ] Validar integridade de arquivo: `7z t [arquivo].7z`
- [ ] Testar restauração completa em disco de teste (optional)
- [ ] Atualizar documentação se regra mudar

---

## 🆘 Troubleshooting

### Problema: Tarefa não executa automaticamente

**Sintomas:** Nenhum backup criado na hora esperada

**Diagnóstico:**
```powershell
# 1. Verificar se tarefa existe
Get-ScheduledTask -TaskName "ShopVivaliz-DailyBackup"

# 2. Ver histórico de execução
Get-ScheduledTaskInfo -TaskName "ShopVivaliz-DailyBackup"

# 3. Checar últimos erros
Get-WinEvent -LogName "Microsoft-Windows-TaskScheduler/Operational" -MaxEvents 10
```

**Solução:**
1. Reinstalar agendador: `.\scripts\install-backup-scheduler.ps1`
2. Verificar se PowerShell está permitido em Execution Policy: `Set-ExecutionPolicy -ExecutionPolicy Bypass -Scope CurrentUser`
3. Garantir que usuário tem permissões admin

### Problema: Backup muito grande

**Sintomas:** Arquivo 7z > 500 MB

**Causa possível:** Diretórios não-excluídos (node_modules, .git, etc)

**Verificação:**
```powershell
# Tamanho de diretórios principais
ls -r "C:\Users\FRED\site-shopvivaliz" | measure -s "length" | select -ExpandProperty Sum
```

**Solução:** Adicionar mais exclusões em `backup-daily.ps1`

### Problema: Espaço em disco insuficiente

**Sintomas:** Erro "Disco cheio" ao criar backup

**Solução:**
1. Aumentar retenção: `.\scripts\backup-daily.ps1 -RetentionDays 5` (reduce para 5 dias)
2. Mover backups: `.\scripts\backup-daily.ps1 -BackupDir "E:\backups"`
3. Limpar backups antigos manualmente:
   ```powershell
   Get-ChildItem "C:\backups\site-shopvivaliz\" -Filter "*.7z" | Sort LastWriteTime -Descending | Select -Skip 5 | Remove-Item
   ```

### Problema: Restauração falha ("Cannot extract")

**Sintomas:** Arquivo 7z corrompido ou incompleto

**Diagnóstico:**
```powershell
# Testar integridade
7z t "C:\backups\site-shopvivaliz\site-shopvivaliz-2026-07-24.7z"
```

**Solução:**
1. Usar safeguard: `.\scripts\restore-backup.ps1 -BackupFile "C:\backups\site-shopvivaliz\safeguard\pre-restore-*.7z"`
2. Usar backup anterior: `.\scripts\restore-backup.ps1` (listar e escolher outro)

---

## 📊 Monitoramento de Saúde

### Verificar Backups Recentes

```powershell
Get-ChildItem "C:\backups\site-shopvivaliz\" -Filter "site-shopvivaliz-*.7z" | Sort LastWriteTime -Descending | Select Name, LastWriteTime, @{L='Size(MB)';E={$_.Length / 1MB -as [int]}} | Format-Table -AutoSize
```

**Esperado:**
```
Name                              LastWriteTime       Size(MB)
----                              -------------       --------
site-shopvivaliz-2026-07-24.7z   7/24/2026 2:00 AM      120
site-shopvivaliz-2026-07-23.7z   7/23/2026 2:00 AM      118
site-shopvivaliz-2026-07-22.7z   7/22/2026 2:00 AM      119
...
```

### Verificar Espaço Livre

```powershell
# Espaço livre em C:\
(Get-Volume -DriveLetter C).SizeRemaining / 1GB

# Tamanho total de backups
(Get-ChildItem "C:\backups\site-shopvivaliz\" -Filter "*.7z" -Recurse | Measure -Sum Length).Sum / 1GB
```

### Verificar Logs de Erro

```powershell
# Buscar erros nos últimos 7 dias
Get-ChildItem "C:\backups\site-shopvivaliz\logs\" -Filter "*.log" | Where-Object { (New-TimeSpan -Start $_.LastWriteTime -End (Get-Date)).Days -le 7 } | ForEach-Object { Select-String "ERROR" $_.FullName }
```

---

## 🔐 Segurança

### O que é Incluído

✅ Todos os arquivos do repositório Git  
✅ Configuração de aplicação  
✅ Scripts  
✅ Histórico de commits  

### O que é Excluído (Segurança)

❌ `.git/` (repositório Git, use Git hooks em vez disso)  
❌ `.vscode/` (configurações locais)  
❌ `node_modules/` (reconstruível)  
❌ `.claude/` (worktrees locais)  
❌ `/logs/` (logs transitórios)  

### Proteção de Arquivo

- ✅ Arquivo comprimido com senha: ❌ Não (adicionar se necessário)
- ✅ Permissões de arquivo: Windows ACL (user only)
- ✅ Integridade: 7-Zip CRC64

**Recomendação:** Se contém secrets, criptografar com:
```powershell
# Adicionar flag à backup-daily.ps1:
-p"SenhaSegura123" -mhe=on
```

---

## 📞 Suporte & Manutenção

### Comando Rápido de Backup Manual

```powershell
cd C:\Users\FRED\site-shopvivaliz && .\scripts\backup-daily.ps1
```

### Desinstalar Agendador

```powershell
# Como administrador:
Unregister-ScheduledTask -TaskName "ShopVivaliz-DailyBackup" -Confirm:$false
```

### Alterar Hora de Backup

```powershell
cd C:\Users\FRED\site-shopvivaliz
.\scripts\install-backup-scheduler.ps1 -Time "03:30"
```

---

## 📈 Histórico de Revisões

| Data | Versão | Mudanças |
|------|--------|----------|
| 2026-07-24 | 1.0 | Versão inicial, 10 dias retenção |

---

**Última atualização:** 2026-07-24  
**Status:** ✅ ATIVO  
**Próxima auditoria:** 2026-08-07
