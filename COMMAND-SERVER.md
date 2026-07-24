# Servidor de Comandos Seguro

Permite executar comandos locais no PC a partir do iPhone ou remoto, com autenticação e validação.

## 🚀 Iniciar Servidor

### No PC (PowerShell)

```powershell
# Apenas localhost (seguro, sem acesso externo)
.\start-command-server.ps1

# Para acessar do iPhone (mesma rede WiFi)
# 1. Descobrir seu IP local:
ipconfig

# 2. Iniciar servidor naquele IP:
.\start-command-server.ps1 -Ip "192.168.x.x"
```

### No Terminal Node direto

```bash
node secure-command-server.js
```

## 📱 Usar do iPhone

### Via Curl (Shortcuts ou Terminal)

```bash
curl -X POST http://192.168.x.x:5557/execute \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -H "Content-Type: application/json" \
  -d '{"cmd": "git status", "timeout": 30}'
```

### Via Shortcuts (iOS Automation)

1. Abrir app **Shortcuts**
2. Criar novo atalho
3. Adicionar ação: **Web Request**
   - Method: `POST`
   - URL: `http://192.168.x.x:5557/execute`
   - Headers:
     ```
     Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
     Content-Type: application/json
     ```
   - Body (JSON):
     ```json
     {
       "cmd": "git status",
       "timeout": 30
     }
     ```
4. Adicionar ação: **Ask for** (para input do usuário)
5. Substituir `"git status"` pela variável capturada

### Via Python/Requests

```python
import requests

TOKEN = "hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"
SERVER = "http://192.168.x.x:5557"

response = requests.post(
    f"{SERVER}/execute",
    headers={"Authorization": f"Bearer {TOKEN}"},
    json={"cmd": "git status", "timeout": 30}
)

print(response.json())
```

## ✅ Comandos Permitidos

Apenas estes comandos podem ser executados (whitelist):

### Git
- `git status`
- `git log`
- `git add`
- `git commit`
- `git push`
- `git pull`
- `git fetch`
- `git reset`
- `git branch`
- `git checkout`
- `git diff`

### NPM
- `npm install`
- `npm run`
- `npm start`
- `npm test`
- `npm list`
- `npm update`

### Sistema
- `dir` / `ls` (qualquer pasta)
- `cat` / `type` (qualquer arquivo)
- `curl` (qualquer URL)
- `python --version`
- `node --version`

### Negados (bloqueados)
❌ `rm`, `del`, `format`, `shutdown`  
❌ Qualquer comando não-whitelisted  
❌ Comandos com pipes/redirect para evitar injeção

## 🔒 Segurança

| Aspecto | Proteção |
|---------|----------|
| Autenticação | Token Bearer obrigatório |
| Comando | Whitelist validada antes de executar |
| Timeout | Max 60 segundos por comando |
| Payload | Max 10KB JSON |
| Output | Max 1MB resposta |
| Rate Limit | Min 1s entre requisições do mesmo IP |
| Logging | Tudo registrado em `logs/command-server.log` |

## 📋 Health Check

```bash
curl -X GET http://192.168.x.x:5557/status \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"
```

Retorna lista de comandos permitidos.

## 🚨 Troubleshooting

### "Connection refused"
- [ ] Servidor não está rodando
- [ ] IP errado (use `ipconfig` para descobrir)
- [ ] Firewall bloqueando porta 5557

### "Unauthorized"
- [ ] Token inválido ou faltando
- [ ] Não incluir "Bearer " no header

### "Command not allowed"
- [ ] Comando não está na whitelist
- [ ] Use `/status` para ver lista permitida

### "Too many requests"
- [ ] Aguardar 1 segundo entre requisições

## 🔧 Modificar Whitelist

Editar em `secure-command-server.js`, seção `ALLOWED_COMMANDS`:

```javascript
const ALLOWED_COMMANDS = {
  'seu-comando': ['arg1', 'arg2'], // Apenas estes args
  'outro-cmd': true,                // Qualquer arg
};
```

Depois reiniciar servidor.

## ⚠️ Aviso de Segurança

1. **HTTP não-criptografado** — qualquer um na rede vê o token
2. **Token em plaintext** — mude se usar em rede pública
3. **Sem HTTPS** — use apenas em rede local confiável ou VPN
4. **Sem autenticação multi-fator**

Para ambiente de produção:
- [ ] Configurar HTTPS com certificado
- [ ] Usar OAuth2 ou JWT
- [ ] Implementar rate limiting mais agressivo
- [ ] Usar VPN para acesso remoto
