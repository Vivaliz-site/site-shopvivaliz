# 🚀 Setup Automático - Claude Code + Chat Acesso Remoto

## ⏱️ Tempo Total: 5 minutos (uma única execução!)

---

## PASSO 1️⃣: Execute o Bootstrap na Máquina Remota

### Para Ubuntu/Linux (SSH):

```bash
# 1. Conecte à sua máquina remota
ssh shopvivaliz@137.131.156.17

# 2. Baixe o script bootstrap
curl -o /tmp/bootstrap-claude.sh https://raw.githubusercontent.com/fredmourao-ai/site-shopvivaliz/main/bootstrap-claude-access.sh

# 3. Execute com sudo (UMA ÚNICA VEZ)
sudo bash /tmp/bootstrap-claude.sh
```

**Isso vai automaticamente:**
- ✅ Configurar usuário `shopvivaliz` se não existir
- ✅ Gerar chaves SSH para Claude Code
- ✅ Instalar MCP Server na porta 5556
- ✅ Configurar daemon de auto-sync
- ✅ Ativar SSH com acesso automático
- ✅ Retornar as credenciais que você precisa

---

## PASSO 2️⃣: Copie a Chave SSH (Output do Script)

Após executar o bootstrap, você verá:

```
📋 CREDENCIAIS E INFORMAÇÕES:

Usuário: shopvivaliz
Host: 137.131.156.17
SSH Port: 22
MCP Port: 5556

Chave SSH Pública para Claude Code:
---
ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAACAQC... claude-code@shopvivaliz
---
```

**⚠️ COPIE A CHAVE SSH PÚBLICA (ssh-rsa AAAA...)** - você vai precisar dela no próximo passo!

---

## PASSO 3️⃣: Configure Claude Code

### No VS Code com Claude integrado:

```
1. Pressione: Ctrl+Shift+P
2. Digite: "Claude: Open Settings"
3. Vá para: SSH Configuration
4. Adicione:

   Host: 137.131.156.17
   User: shopvivaliz
   Port: 22
   IdentityFile: [caminho da chave privada]
   StrictHostKeyChecking: no
```

Ou mais simples - use a chave pública em `authorized_keys` (já feito pelo bootstrap).

**Teste:**
```bash
ssh shopvivaliz@137.131.156.17 "whoami"
# Deve retornar: shopvivaliz
```

---

## PASSO 4️⃣: Configure Claude Chat

### No claude.ai ou Claude Desktop:

```
1. Abra: Settings → MCP Servers
2. Adicione nova conexão:

   Name: ShopVivaliz Remote
   Type: HTTP
   URL: http://137.131.156.17:5556
   
3. Teste clicando em "Health Check"
```

**Ou via CLI:**
```bash
curl -X GET http://137.131.156.17:5556/status
# Deve retornar: {"status":"online", ...}
```

---

## 🎯 Pronto! Agora você pode:

### Claude Code (Development):
```bash
ssh shopvivaliz@137.131.156.17

# Qualquer comando aqui funciona:
cd /home/shopvivaliz/site-shopvivaliz
git log
npm install
npm run build
git push
```

### Claude Chat (Monitoring):
```bash
# Status da máquina
curl http://137.131.156.17:5556/status

# Logs recentes
curl -H "X-Lines: 50" http://137.131.156.17:5556/logs

# Saúde do sistema
curl http://137.131.156.17:5556/health

# Executar comando remoto
curl -X POST http://137.131.156.17:5556/exec \
  -H "Content-Type: application/json" \
  -d '{"cmd":"systemctl status shopvivaliz-sync"}'
```

---

## ✅ Checklist Final

- [ ] Bootstrap executado com sucesso na máquina remota
- [ ] Copiei a chave SSH pública
- [ ] Claude Code consegue fazer SSH: `ssh shopvivaliz@137.131.156.17 "whoami"`
- [ ] Claude Chat consegue acessar MCP: `curl http://137.131.156.17:5556/status`
- [ ] MCP Server retorna JSON com status
- [ ] Services rodando: `systemctl status shopvivaliz-mcp && systemctl status shopvivaliz-sync`

---

## 🚨 Troubleshooting

### ❌ SSH Connection Refused
```bash
# Verifique se SSH está rodando:
sudo systemctl status ssh
sudo systemctl restart ssh
```

### ❌ MCP Port 5556 Not Responding
```bash
# Verifique o serviço MCP:
sudo systemctl status shopvivaliz-mcp
sudo systemctl restart shopvivaliz-mcp

# Veja logs:
sudo journalctl -u shopvivaliz-mcp -n 20
```

### ❌ Permission Denied SSH
```bash
# Verifique authorized_keys:
ls -la ~/.ssh/authorized_keys
cat ~/.ssh/authorized_keys

# Regenere se necessário:
ssh-keygen -t rsa -b 4096 -f ~/.ssh/id_rsa -N ""
cat ~/.ssh/id_rsa.pub >> ~/.ssh/authorized_keys
```

### ❌ Sync não está funcionando
```bash
# Verifique o repositório Git:
cd /home/shopvivaliz/site-shopvivaliz
git remote -v
git status

# Verifique o serviço:
sudo systemctl status shopvivaliz-sync
sudo journalctl -u shopvivaliz-sync -n 20
```

---

## 📋 O que foi instalado automaticamente

```
├── /home/shopvivaliz/.ssh/
│   ├── claude_code_rsa (chave privada)
│   ├── claude_code_rsa.pub (chave pública)
│   └── authorized_keys (permite SSH)
│
├── /home/shopvivaliz/mcp-server/
│   └── app.py (MCP HTTP Server na porta 5556)
│
├── /etc/systemd/system/shopvivaliz-mcp.service
│   └── Auto-inicia MCP Server
│
├── /etc/systemd/system/shopvivaliz-sync.service
│   └── Auto-sincroniza repositório a cada 30s
│
└── SSH Server (porta 22)
    └── Pronto para Claude Code
```

---

## 🎓 Resumo do Fluxo

```
1️⃣ Você executa bootstrap-claude-access.sh na máquina remota
   ↓
2️⃣ Script cria usuário, SSH, MCP Server e sync daemon
   ↓
3️⃣ Script retorna a chave SSH pública
   ↓
4️⃣ Você configura Claude Code com SSH
   ↓
5️⃣ Você configura Claude Chat com MCP HTTP
   ↓
6️⃣ PRONTO! Ambos têm acesso total e automático
```

---

## 🔐 Segurança

- ✅ Chaves SSH são geradas localmente na máquina
- ✅ Nenhuma credencial é exposta
- ✅ SSH usa autenticação por chave (mais seguro que senha)
- ✅ MCP Server roda com permissões do usuário `shopvivaliz` (não root)
- ✅ Firewall permite apenas portas 22 e 5556 (configure conforme necessário)

---

## ❓ Dúvidas?

Se algo não funcionar, compartilhe o output completo do bootstrap script para diagnóstico.

**Comando para obter informações de diagnóstico:**
```bash
echo "=== SSH ===" && \
systemctl status ssh && \
echo "" && \
echo "=== MCP ===" && \
systemctl status shopvivaliz-mcp && \
echo "" && \
echo "=== SYNC ===" && \
systemctl status shopvivaliz-sync && \
echo "" && \
echo "=== LOGS ===" && \
journalctl -u shopvivaliz-mcp -n 10
```

---

*Última atualização: 2026-07-16*
