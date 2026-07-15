# 🌉 Ponte de Comunicação entre Agentes - Múltiplas Estações

**Sistema:** ShopVivaliz Auto-Sync Inter-Estações  
**Status:** ✅ Implementado e Pronto  
**Data:** 2026-07-13

---

## 📋 O Que Foi Criado

| Componente | Arquivo | Função |
|-----------|---------|--------|
| **Template de Issue** | `.github/ISSUE_TEMPLATE/agentes-requisicao.md` | Criar requisições estruturadas |
| **Script Leitor** | `scripts/agentes-leitor.py` | Monitorar e executar issues |
| **Workflow GitHub** | `.github/workflows/agentes-listener.yml` | Disparar automático (webhook) |
| **Configuração** | `.env.agentes` | Variáveis de ambiente por estação |

---

## 🚀 Como Usar

### 1️⃣ Criar Requisição (De Qualquer Estação)

**Opção A: Via GitHub UI**
```
GitHub → Issues → New Issue → 🤖 Requisição Para Agentes Autônomos
```

**Opção B: Via CLI**
```bash
gh issue create \
  --title "Tarefa: Sincronizar dados de Shopee" \
  --label agentes \
  --body "..."
```

### 2️⃣ Monitorar Issues (Em Qualquer Estação)

**Uma única execução:**
```bash
python scripts/agentes-leitor.py --env windows-local
```

**Modo contínuo (watch):**
```bash
python scripts/agentes-leitor.py --env ubuntu-vm --watch --poll 30
```

**Com GitHub Actions (automático a cada 5 min):**
```
✅ Já está configurado em .github/workflows/agentes-listener.yml
```

### 3️⃣ Configurar Estação

Copiar `.env.agentes` para cada máquina:

**Windows:**
```powershell
Copy-Item .env.agentes -Destination .env.agentes.local
# Editar com IP/hostname da máquina
```

**Ubuntu:**
```bash
cp .env.agentes .env.agentes.local
# Editar com IP/hostname da máquina
```

---

## 🔄 Fluxo Completo

```
┌─────────────────────────────────────────────────────────────────┐
│                        ESTAÇÃO A (Windows)                       │
│  1. Claude cria Issue com tarefa                                │
│     Título: "Sincronizar produtos com Shopee"                  │
│     Label: "agentes" + "critico"                               │
│                                                                  │
└──────────────────────┬──────────────────────────────────────────┘
                       │ (git commit + push)
                       ↓
        ┌──────────────────────────────┐
        │     GitHub Repository         │
        │  - Issue #281 criada          │
        │  - Webhook ativa              │
        │  - Auto-sync cada 30 min      │
        └──────────────────┬────────────┘
                           │
           ┌───────────────┼───────────────┐
           ↓               ↓               ↓
    ┌────────────┐  ┌────────────┐  ┌────────────┐
    │  Estação A │  │  Estação B │  │ Estação C  │
    │ (Windows)  │  │  (Ubuntu)  │  │  (Remote)  │
    │            │  │            │  │            │
    │ agentes-   │  │ agentes-   │  │ agentes-   │
    │ leitor.py  │  │ leitor.py  │  │ leitor.py  │
    │ --watch    │  │ --watch    │  │ --watch    │
    │            │  │            │  │            │
    │ Vê Issue   │  │ Vê Issue   │  │ Vê Issue   │
    │ Comenta:   │  │ Comenta:   │  │ Comenta:   │
    │ "Execut... │  │ "Execut... │  │ "Execut... │
    │            │  │            │  │            │
    │ 🚀 Ro...   │  │ 🚀 Ro...   │  │ 🚀 Ro...   │
    │            │  │            │  │            │
    │ ✅ Conc... │  │ ✅ Conc... │  │ ✅ Conc... │
    └────────────┘  └────────────┘  └────────────┘
           │               │               │
           └───────────────┼───────────────┘
                           ↓
        ┌────────────────────────────────┐
        │     GitHub Issue Atualizada    │
        │  - Comentários de cada estação │
        │  - Label: concluido            │
        │  - Timeline: 3 execuções       │
        │  - Logs disponíveis            │
        └────────────────────────────────┘
```

---

## 📝 Exemplo de Requisição

### Issue Criada (Estação A):

```markdown
## 🤖 Requisição Para Agentes Autônomos

**De:** Windows Local
**Para:** Todos os agentes
**Prioridade:** 🔴 Crítica
**Deadline:** 2026-07-13T22:00:00Z

### Descrição da Tarefa

Sincronizar catálogo de produtos com Shopee de forma bidirecional.

### Steps a Executar

- [ ] Step 1: Conectar com API Shopee
- [ ] Step 2: Buscar produtos novos
- [ ] Step 3: Atualizar preços
- [ ] Step 4: Testar sincronização
- [ ] Step 5: Fazer commit e push
- [ ] Step 6: Comentar resultado

### Arquivos Envolvidos

- `scripts/shopee-sync.py`
- `.env.shopee`

### Secrets Necessárias

- `SHOPEE_PARTNER_KEY`
- `SHOPEE_SHOP_ID`

### Ambientes Afetados

- [x] Ubuntu VM Oracle
- [ ] Windows Local (apenas observar)

### Resultado Esperado

Produtos sincronizados em tempo real com Shopee.
```

### Resposta de Estação B (Ubuntu):

```markdown
🚀 **[ubuntu-vm]** Iniciando execução em 2026-07-13 20:45:33 UTC

Processando steps...
✅ Conectado com Shopee
✅ 47 produtos sincronizados
✅ Preços atualizados
✅ Testes passaram
✅ Commit realizado: `feat: shopee sync 2026-07-13`
✅ Push para main concluído

✅ **[ubuntu-vm]** Concluído com sucesso

- Tempo: 2026-07-13 20:47:12 UTC
- Ambiente: ubuntu-vm
- Logs: logs/agentes-leitor-2026-07-13.log
```

---

## 🔧 Configuração Avançada

### Modo Daemon (Sempre Rodando)

**Windows (Task Scheduler):**
```powershell
$action = New-ScheduledTaskAction -Execute "python.exe" `
  -Argument "scripts\agentes-leitor.py --env windows-local --watch"

$trigger = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERNAME" -LogonType Interactive

Register-ScheduledTask -TaskName "ShopVivaliz-Agentes-Listener" `
  -Action $action -Trigger $trigger -Principal $principal
```

**Ubuntu (Systemd):**
```bash
# Criar /etc/systemd/system/shopvivaliz-agentes.service
[Unit]
Description=ShopVivaliz Agentes Listener
After=network.target

[Service]
Type=simple
User=ubuntu
WorkingDirectory=/home/ubuntu/site-shopvivaliz
ExecStart=/usr/bin/python3 scripts/agentes-leitor.py --env ubuntu-vm --watch
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target

# Ativar
sudo systemctl enable shopvivaliz-agentes.service
sudo systemctl start shopvivaliz-agentes.service
```

### Custom Executors

Pode modificar `scripts/agentes-leitor.py` para adicionar:
- Integração com Jenkins/CircleCI
- Webhooks customizados
- Notificações (Slack, Email)
- Execução de scripts customizados

---

## 📊 Logs e Monitoramento

**Logs locais:**
```bash
tail -f logs/agentes-leitor-2026-07-13.log
```

**GitHub Activity:**
```bash
gh issue list --label agentes --state closed --limit 10
```

**Status do Sistema:**
```bash
python scripts/agentes-leitor.py --env $(hostname) --poll 60 --watch
```

---

## 🎯 Próximos Passos

1. **Agentes Ubuntu:** Rodar `python scripts/agentes-leitor.py --env ubuntu-vm --watch`
2. **Agentes Remoto:** Rodar `python scripts/agentes-leitor.py --env windows-remote --watch`
3. **Monitorar Issues:** Verificar https://github.com/Vivaliz-site/site-shopvivaliz/issues?q=label%3Aagentes
4. **Documentar Estações:** Atualizar CLAUDE.md com IPs/hostnames

---

## ⚠️ Notas Importantes

- **Não sobrescrever:** Respeitar branch protection e não fazer force-push
- **Segurança:** GITHUB_TOKEN deve estar em GitHub Secrets, não no .env
- **Conflitos:** Se dois agentes editarem ao mesmo time, usar `git rebase --continue`
- **Logs:** Limpar logs antigos com script de manutenção

---

**Implementado por:** Claude Code  
**Data:** 2026-07-13T20:35:00Z  
**Próxima Manutenção:** 2026-07-20
