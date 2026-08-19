# Fred-Win — relay privado canônico e protocolo de status

**Efetivo:** 2026-08-18  
**Escopo:** qualquer agente, workflow ou operador que precise acessar, diagnosticar ou declarar o estado do Fred-Win/MCP.

> **REGRA CRÍTICA:** o estado do Fred-Win só pode ser classificado pela rota privada canônica descrita neste documento. Falha em endpoint histórico, ausência de conector direto na sessão ou falta de evidência não significam que o Fred-Win está inativo.

## Arquitetura oficial

```text
GitHub Actions
  -> SSH para Oracle VM 137.131.156.17
  -> VM 127.0.0.1:5557
  -> reverse SSH tunnel
  -> Fred-Win 127.0.0.1:5557
  -> MCP
```

Componentes canônicos:

- Workflow: `.github/workflows/fred-win-remote-action.yml`
- Request: `ops/fredwin-request.json`
- MCP local no Fred-Win: `http://127.0.0.1:5557/health`
- Relay visto pela Oracle VM: `http://127.0.0.1:5557/health`
- Script do túnel gerenciado: `scripts/ssh-tunnel-service-managed.ps1`
- Bootstrap do MCP: `scripts/fredwin-remote-bootstrap.ps1`
- Log do bootstrap: `C:\site-shopvivaliz\logs\fredwin-remote-bootstrap.log`
- Log do túnel: `C:\site-shopvivaliz\logs\fredwin-managed-tunnel.log`

O túnel esperado no Windows usa `ssh.exe` com reverse forward equivalente a:

```text
-R 5557:127.0.0.1:5557 ubuntu@137.131.156.17
```

## Endpoint antigo — proibido para diagnóstico atual

`https://rce-shopvivaliz.trycloudflare.com` é um endpoint histórico, abandonado e desalinhado com a arquitetura atual.

Regras:

1. **Nunca** usar esse hostname para decidir se o Fred-Win está ativo.
2. Timeout, DNS, HTTP 4xx/5xx ou indisponibilidade nesse hostname não são evidência sobre o relay privado.
3. Não substituir o relay privado por exposição pública do MCP.
4. Workflows ou relatórios históricos que citem Cloudflare devem ser interpretados apenas como contexto antigo.

## Protocolo obrigatório antes de declarar estado

### 1. Ler as fontes atuais

Antes de qualquer diagnóstico, conferir:

- este documento;
- `.github/workflows/fred-win-remote-action.yml`;
- `ops/fredwin-request.json`;
- `reports/fredwin-remote-access-repair-2026-08-07.md` apenas como histórico complementar.

### 2. Executar primeiro a ação allowlisted `health`

O request deve conter:

```json
{
  "action": "health"
}
```

O workflow deve seguir a rota GitHub Actions -> Oracle VM -> loopback `127.0.0.1:5557` -> reverse tunnel -> Fred-Win.

### 3. Validar a resposta esperada

O estado é **COMPROVADO / ATIVO** somente quando a rota canônica retorna HTTP 200 e uma resposta compatível com:

```text
status=ok
environment=fred-win
mcp_version=1.0.0
```

Registrar data/hora absoluta, workflow/run ou commit usado e os campos recebidos.

### 4. Classificar corretamente

| Classificação | Quando usar |
|---|---|
| **COMPROVADO / ATIVO** | O `health` passou pela rota privada canônica e retornou os campos esperados. |
| **FALHOU / INATIVO** | A rota canônica falhou **e** a investigação confirmou evidência objetiva de serviço/túnel parado ou quebrado. |
| **INCONCLUSIVO** | Não foi possível executar o workflow, acessar a VM, ler os logs ou obter evidência suficiente. |

**INCONCLUSIVO nunca deve ser reescrito como INATIVO.**

## O que não prova inatividade

Nenhum dos itens abaixo autoriza afirmar que o Fred-Win está inativo:

- o endpoint `trycloudflare.com` não responder;
- o conector Desktop Commander/Remote Desktop não aparecer na sessão atual;
- a sessão do chat não enxergar diretamente a pasta Downloads do Windows;
- não existir workflow recente;
- o navegador não conseguir abrir `127.0.0.1:5557` diretamente;
- não ser possível observar o processo Windows a partir da sessão atual;
- uma ferramenta preferida estar indisponível;
- não ter sido executado o `health` canônico.

Nesses casos, a classificação correta é **INCONCLUSIVO** até que a rota oficial seja testada.

## Diagnóstico em ordem segura

1. Executar `health` em `.github/workflows/fred-win-remote-action.yml`.
2. Confirmar que o passo `Check private MCP path through VM` alcançou `http://127.0.0.1:5557/health` via SSH para a Oracle VM.
3. Se falhar, verificar na Oracle VM se o loopback `127.0.0.1:5557` está acessível.
4. No Fred-Win, confirmar o `health` local em `http://127.0.0.1:5557/health`.
5. Confirmar os processos esperados:
   - `powershell.exe` executando `ssh-tunnel-service-managed.ps1`;
   - `ssh.exe` com o reverse forward da porta 5557.
6. Ler, nesta ordem:
   - `C:\site-shopvivaliz\logs\fredwin-remote-bootstrap.log`;
   - `C:\site-shopvivaliz\logs\fredwin-managed-tunnel.log`.
7. Só depois da causa comprovada, reparar o componente específico. Não redesenhar a arquitetura nem reativar Cloudflare como atalho.

## Uso de ações remotas

1. Sempre executar `health` primeiro.
2. Depois do `health` aprovado, usar apenas ações presentes no `case` allowlisted de `.github/workflows/fred-win-remote-action.yml`.
3. Nunca enviar comando arbitrário fora da allowlist.
4. O relay privado não amplia permissões de GitHub, produção, Microsoft 365, pagamentos, ERP ou secrets.

## Modelo de evidência

```text
FRED_WIN_STATUS=COMPROVADO|FALHOU|INCONCLUSIVO
CHECKED_AT=<timestamp absoluto>
CANONICAL_PATH=GitHub Actions -> Oracle VM 137.131.156.17 -> 127.0.0.1:5557 -> reverse SSH -> Fred-Win
WORKFLOW=.github/workflows/fred-win-remote-action.yml
REQUEST_ACTION=health
HTTP_STATUS=<status observado>
HEALTH_STATUS=<valor observado>
ENVIRONMENT=<valor observado>
MCP_VERSION=<valor observado>
EVIDENCE=<workflow run, commit, log ou erro verificável>
```

## Regra final para agentes

Nunca escreva “Fred-Win está inativo” por inferência. Primeiro teste a rota privada canônica. Sem essa prova, use **INCONCLUSIVO** e descreva exatamente qual evidência faltou.
