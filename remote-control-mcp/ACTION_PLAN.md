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
STATUS=PASS

### Evidência
- Fred-Win configurado no controller em `127.0.0.1:2222`.
- KOCEPSV configurado no controller em `127.0.0.1:2223`.
- Túnel gerenciado do KOCEPSV persiste simultaneamente `-R 2223:127.0.0.1:22` e `-R 5558:127.0.0.1:5557`.
- Bootstrap do KOCEPSV exige ambos os forwards; túnel legado incompleto é reiniciado.
- Workflow deixa de depender de descoberta Tailscale/TCP 22 direto para Windows e usa os reverse SSH privados.
- Relay MCP legado `5558` fica restrito ao bootstrap/recovery inicial para abrir `2223`.
- Host keys são pinadas para `[127.0.0.1]:2222` e `[127.0.0.1]:2223`.
- Cleanup dos forwards temporários do runner foi deduplicado e coberto por regressão.
- CI da branch: Remote Control MCP CI run `36370863304` = SUCCESS no commit `8756e28d88dbbd0b2ff8a9de6e2e833c6839d3c8`.
- O clone local de validação não foi usado como evidência porque o runtime local não resolveu `github.com`; o CI remoto forneceu a execução canônica dos testes.

## Etapa 3 — Validar e mesclar PR
STATUS=PASS

### Evidência
- PR #1974 mesclado ao main em `1d7425a96702ec8c974230a86db0268f68391496` (2026-09-28T02:47:22Z).
- Todos os 5 checks obrigatórios no head do merge foram SUCCESS: Remote Control MCP CI, Mandatory Validation Gate, Repository Governance, ShopVivaliz QA, Desktop Commander 24h Health.

## Etapa 4 — Bootstrap Windows
STATUS=RUNNING

### Evidência
- Fred-Win (`2222`): comprovado ao vivo — bootstrap run `36374487789` (2026-09-28T03:42Z, o mais recente até o momento) imprime `REMOTE_CONTROL_FRED_REVERSE_SSH=PASS`.
- KOCEPSV (`2223`): **ainda não comprovado**, apesar de três PRs de correção mesclados diretamente ao main no mesmo dia após o merge da Etapa 3:
  - #2003 `tolerate hidden KOCEPSV ssh executable path`
  - #2004 `classify KOCEPSV sidecar bootstrap failures`
  - #2007 `classify KOCEPSV controller invocation failure`
- Reverificação ao vivo nesta sessão (2026-09-28), no run mais recente disponível (`36374487789`, head já contendo o fix do #2007): nenhum marcador `REMOTE_CONTROL_KOCEPSV_*` aparece no log antes do job falhar com exit code 1 — ou seja, mesmo a nova classificação de diagnóstico ainda não é alcançada/impressa, e a porta `2223` continua inacessível a partir do backend nessa execução.
- `docs/knowledge/host-access.md` (atualizado no mesmo dia pelos PRs #2010/#2012) já documenta essa mesma conclusão: "KOCEPSV `2223` ainda não comprovado" — sem divergência entre documentação e evidência ao vivo neste ponto.
- Tentativa de diagnóstico direto no host via Remote Desktop Commander nesta sessão: os quatro dispositivos canônicos estão `online`, mas a chamada foi bloqueada pela cota mensal de tool-calls do RDC esgotada (bloqueio externo transitório de ferramenta, não falha de conexão/pareamento). Diagnóstico ao vivo direto em `DESKTOP-KOCEPSV`/backend fica pendente até a cota renovar ou outro canal ao vivo estar disponível.
- Não repetir o padrão de PR-corretivo-sem-verificação-ao-vivo dos três PRs acima; a próxima ação autorizada é obter estado ao vivo real do host KOCEPSV antes de qualquer novo patch.

### Instrumentação (PR #2014/#2015) e achado real
- PR #2014 e #2015 (diagnóstico apenas, sem tentativa de fix) instrumentaram o bloco do KOCEPSV e o loop compartilhado de instalação da chave admin (FRED + DESKTOP) com marcadores explícitos e dumps `ss -tln`/`ss -tlnp` direto no runner self-hosted (que É o próprio host backend) — contornando a cota esgotada do RDC.
- Run `36410163131`: provou que o túnel do KOCEPSV está de fato de pé na camada TCP (`ss -tln` mostra `LISTEN 127.0.0.1:2223` real, `REMOTE_CONTROL_KOCEPSV_REVERSE_SSH=PASS`). O job falhou 2s depois, no loop ainda não instrumentado.
- Run `36410984419`: com o loop já instrumentado, a falha real apareceu na **primeira iteração, FRED (porta 2222)**, não no KOCEPSV — `ssh-keyscan` falha com `Connection closed by remote host` (×5), mesmo com a porta TCP aberta.
- **Hipótese de trabalho (bem fundamentada, ainda não confirmada diretamente no host):** túnel SSH reverso "zumbi" — o socket local do `-R` continua vinculado no backend mesmo depois que a conexão SSH master real para o Windows morreu; uma conexão nova é aceita e fechada na hora, sem handshake, porque não há mais túnel vivo para repassar. Isso bate exatamente com o padrão observado (TCP cru sempre "abre", SSH de verdade sempre fecha na hora) e explicaria por que tanto FRED quanto KOCEPSV parecem comprovados no nível errado de checagem.
- Isso reenquadra a investigação: o problema provavelmente nunca foi específico dos scripts PowerShell do KOCEPSV (alvo dos PRs #2001/#2003/#2004/#2007) — é um problema de vivacidade/reconexão do túnel reverso compartilhado por ambos os hosts Windows.
- **Nada foi implementado além do diagnóstico** — confirmar a hipótese e corrigi-la exige acesso ao vivo aos hosts (RDC quando a cota renovar, ou outro canal) e/ou uma mudança de workflow que valide conexão SSH real (não só TCP cru) antes de confiar no `PASS`. Ambas mexem em bootstrap SSH ao vivo de hosts Windows de produção — sinalizado ao usuário em vez de implementado sem aprovação.

## Etapa 5 — E2E quatro hosts
STATUS=PENDING

## Etapa 6 — Provar runtime sem GitHub
STATUS=PENDING

## Etapa 7 — Integrar MCP com ChatGPT e encerrar
STATUS=PENDING

## Atualização — 2026-09-28 (sessão atual): SSH real + auth resolvidos nos dois Windows; novo bloqueio é do controller

Entre a última entrada desta sessão e agora, outra sessão/agente avançou 9 commits reagindo a falhas ao vivo (nenhum atualizou este arquivo — mesma lacuna recorrente). Resumo reconstruído via `git log` + evidência ao vivo:
- Causa raiz real do lado Windows: o serviço OpenSSH Server nem sempre estava instalado/registrado como serviço. Corrigido via `scripts/windows-openssh-recovery.ps1` (instala `OpenSSH.Server`, registra `New-Service -Name sshd`), acionado pelos relays legados 5557/5558 quando uma checagem de handshake SSH real (`ssh_protocol_alive()`, não mais TCP cru) falha.
- Problema seguinte: bootstrap tentava autenticar com a chave da VM, nunca autorizada num sshd recém-recuperado (dependência circular). Corrigido instalando a chave pública do próprio controller via os relays já autenticados, depois validando com a chave privada do controller via SSH real 2222/2223 (PR #2035, mesclado como `0c9572cc0`).

**Reverificação ao vivo nesta sessão** (2 runs consecutivos, `36436982503` e `36441085607`, disparado manualmente por mim no tip atual do main): ambos mostram, de forma reproduzível:
```
REMOTE_CONTROL_KOCEPSV_PRECHECK_2223=SSH_OK
REMOTE_CONTROL_ADMIN_KEY_STEP=FRED controller-key auth OK
REMOTE_CONTROL_ADMIN_KEY_STEP=DESKTOP controller-key auth OK
```
**Os dois hosts Windows (Fred-Win e KOCEPSV) agora comprovam SSH real + autenticação com a chave do controller.** O bloqueio de túnel zumbi que motivou o ACTION STAGE 4/5 do CHECKPOINT.md está resolvido.

**Novo bloqueio (corrigido e mesclado nesta sessão):** o passo "Pin private host keys for controller" reinicia `shopvivaliz-remote-control-mcp.service` e testa `curl` no health-check imediatamente, sem espera — `systemctl is-active` só prova que o processo nasceu (`Type=simple`), não que já fez bind na porta 5580. Corrigido com um retry de 10×1s + dump de `systemctl status`/`journalctl` em caso de esgotamento (sem `|| true`/`set +e`, para respeitar `tests/remote-control-mcp-test.py::test_bootstrap_surfaces_do_not_discard_failures`). **PR #2038 mesclado ao main como `2538517acf91963ca79e4694556d8db3c771335c`, com os 6 checks de CI verdes.**

Próxima ação autorizada: rodar o bootstrap ao vivo de novo no main atual (já contendo o PR #2038) e confirmar, com evidência fresca, que o health-check do controller passa de forma confiável — merge sozinho não é prova de comportamento ao vivo, conforme a regra permanente desta tarefa. Se passar, o workflow deve alcançar "Four-host live MCP health validation" (hoje `skipped` porque exige `workflow_dispatch` com `run_e2e: true` explícito) — essa etapa deve ser tentada em seguida, assim que o fix do controller for confirmado ao vivo.
