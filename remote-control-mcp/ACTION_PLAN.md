# Remote Control MCP — Plano de Ação

TASK_ID=private-remote-control-mcp-4hosts-20260927
STATUS=RUNNING

## Regra de execução
Executar uma etapa por vez. Ao concluir cada etapa, persistir evidência em `remote-control-mcp/CHECKPOINT.md` antes de avançar.

## Etapa 1 — Definir rota Windows canônica
STATUS=PASS

### Decisão
- Fred-Win: reverse SSH persistente já existente no backend em `127.0.0.1:2222 -> Fred-Win 127.0.0.1:22`.
- KOCEPSV: adicionar reverse SSH persistente `127.0.0.1:2223 -> KOCEPSV 127.0.0.1:22`.
- A porta `2223` não possui uso canônico conflitante no repositório.
- Relays MCP legados permanecem:
  - Fred-Win `127.0.0.1:5557`
  - KOCEPSV `127.0.0.1:5558`
- O relay MCP do KOCEPSV será usado apenas para o bootstrap inicial do novo reverse SSH, porque hoje não há SSH reverso persistente nesse host.
- Após o bootstrap, o Remote Control MCP usará exclusivamente os reverse SSH privados `2222/2223` para os Windows. Não dependerá de TCP/22 direto via Tailscale.
- O controller continuará loopback-only em `127.0.0.1:5580`.
- GitHub será somente bootstrap/recovery; não fará parte do command transport, queue, heartbeat, execution ou state em runtime.

### Alterações previstas para a Etapa 2
1. `scripts/desktopkocepsv-ssh-tunnel-service-managed.ps1`
   - adicionar `-R 2223:127.0.0.1:22`;
   - manter `-R 5558:127.0.0.1:5557`.
2. `scripts/desktopkocepsv-remote-bootstrap.ps1`
   - exigir exatamente um túnel contendo os dois reverse forwards.
3. `remote-control-mcp/server.py`
   - Fred-Win: SSH em `127.0.0.1:2222`;
   - KOCEPSV: SSH em `127.0.0.1:2223`;
   - remover dependência de descoberta Tailscale para comandos Windows.
4. `.github/workflows/remote-control-mcp-bootstrap.yml`
   - não testar TCP/22 direto nos peers Windows;
   - Fred-Win: usar o reverse SSH já existente;
   - KOCEPSV: usar o relay MCP `5558` uma única vez para atualizar/reiniciar o túnel e abrir `2223`;
   - depois instalar a nova chave administrativa via SSH reverso;
   - pinning de host keys pelas portas 2222/2223.
5. Testes
   - contrato das portas 2222/2223;
   - proibir retorno ao TCP/22 direto via Tailscale no novo control plane;
   - garantir persistência dos dois forwards do KOCEPSV.

## Etapa 2 — Implementar e testar a rota
STATUS=PENDING

## Etapa 3 — Validar e mesclar PR
STATUS=PENDING

## Etapa 4 — Bootstrap Windows
STATUS=PENDING

## Etapa 5 — E2E quatro hosts
STATUS=PENDING

## Etapa 6 — Provar runtime sem GitHub
STATUS=PENDING

## Etapa 7 — Integrar MCP com ChatGPT e encerrar
STATUS=PENDING
