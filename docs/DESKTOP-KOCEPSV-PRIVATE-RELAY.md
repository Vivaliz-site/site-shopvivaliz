# DESKTOP-KOCEPSV — relay privado canônico e protocolo de status

**Efetivo:** 2026-09-17  
**Escopo:** qualquer agente, workflow ou operador que precise acessar, diagnosticar ou declarar o estado do DESKTOP-KOCEPSV/MCP.

> **REGRA CRÍTICA:** o estado do DESKTOP-KOCEPSV só pode ser classificado pela rota privada canônica. Falha do Desktop Commander, quota esgotada ou ausência do conector não significam que o host está inativo.

## Arquitetura oficial

```text
GitHub Actions/private relay
  -> runner self-hosted do site
  -> SSH privado para backend A1 10.0.1.38
  -> backend 127.0.0.1:5558
  -> reverse SSH tunnel
  -> DESKTOP-KOCEPSV 127.0.0.1:5558
  -> MCP
```

Componentes canônicos:

- Workflow: `.github/workflows/desktopkocepsv-remote-action.yml`
- Request: `ops/desktopkocepsv-remote-request.json`
- Health pela VM: `http://127.0.0.1:5558/health`
- Endpoint allowlisted: `http://127.0.0.1:5558/mcp/tool/execute_command`
- Script do túnel: `scripts/desktopkocepsv-ssh-tunnel-service-managed.ps1`
- Bootstrap: `scripts/desktopkocepsv-remote-bootstrap.ps1`
- Relay 24h esperado: `ShopVivaliz DESKTOP-KOCEPSV Relay 24h`

O reverse forward esperado é equivalente a:

```text
-R 5558:127.0.0.1:5558 ubuntu@${SHOPVIVALIZ_BACKEND_SSH_HOST}
```

## Health autoritativo

O estado só é **COMPROVADO / ATIVO** quando a rota privada retorna HTTP 200 e dados compatíveis com:

```text
status=ok
environment=desktop-kocepsv
mcp_version=<não vazio>
```

Modelo de evidência:

```text
DESKTOP_KOCEPSV_STATUS=COMPROVADO|FALHOU|INCONCLUSIVO
CHECKED_AT=<timestamp absoluto>
CANONICAL_PATH=GitHub Actions -> backend A1 10.0.1.38 -> 127.0.0.1:5558 -> reverse SSH -> DESKTOP-KOCEPSV
WORKFLOW=.github/workflows/desktopkocepsv-remote-action.yml
REQUEST_ACTION=health
HTTP_STATUS=<status observado>
HEALTH_STATUS=<valor observado>
ENVIRONMENT=<valor observado>
MCP_VERSION=<valor observado>
EVIDENCE=<workflow run, commit, log ou erro verificável>
```

## Ações permitidas no caminho primário

O workflow primário aceita somente ações explicitamente allowlisted. O contrato atual inclui:

```text
health
runtime_identity
```

`runtime_identity` executa um comando PowerShell fixo e retorna somente:

```text
COMPUTER=<nome do computador>
USER=<identidade Windows>
PWD=<diretório atual>
```

Nunca aceitar texto arbitrário de comando por `workflow_dispatch` ou pelo JSON de request.

## Diagnóstico em ordem segura

1. Executar `health` em `.github/workflows/desktopkocepsv-remote-action.yml`.
2. Confirmar que o backend A1 é alcançável em `10.0.1.38` por SSH privado.
3. Confirmar que `http://127.0.0.1:5558/health` responde no backend.
4. Se houver `Connection refused`, classificar como **INCONCLUSIVO** até distinguir túnel parado de MCP local parado.
5. No DESKTOP-KOCEPSV, verificar o MCP local e a tarefa `ShopVivaliz DESKTOP-KOCEPSV Relay 24h` sem abrir navegador oculto.
6. Confirmar o `ssh.exe` responsável pelo reverse forward 5558.
7. Reparar somente o componente comprovadamente falho.

## O que não prova inatividade

- quota do Desktop Commander em 0%;
- provider DC desconectado;
- `AUTH_REQUIRED` do DC;
- ausência de dispositivo DC na sessão atual;
- workflow antigo sem execução recente;
- endpoint público histórico indisponível;
- não conseguir abrir `127.0.0.1:5558` a partir do navegador do operador.

Nesses casos, use **INCONCLUSIVO** até testar a rota privada canônica.

## Segurança

- Não expor MCP em `0.0.0.0`.
- Não criar endpoint público `trycloudflare.com` como atalho.
- Não registrar token, cookie, device code, session blob, chave privada ou conteúdo de secret.
- Usar `StrictHostKeyChecking=yes` no SSH automatizado.
- O Desktop Commander permanece **fallback/provider transport**, não o plano de controle primário.

## Prioridade operacional

1. **GitHub Actions/private relay**.
2. **OCI Bastion** quando shell OCI direto for necessário.
3. **Desktop Commander fallback** somente quando quota/provider estiverem disponíveis.
