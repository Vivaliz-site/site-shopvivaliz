# Acesso a hosts — bootstrap obrigatório para agentes

Este documento é a referência canônica de **como localizar e acessar os hosts ShopVivaliz** ao iniciar uma conversa, sessão de IDE ou agente novo.

## Regra zero

Antes de diagnosticar, alterar ou validar qualquer ambiente, o agente deve:

1. ler `docs/knowledge/README.md` e `docs/knowledge/agent-rules.md`;
2. identificar o host correto pelo papel atual;
3. confirmar o acesso com evidência (`hostname`, `whoami`, diretório e, quando aplicável, `git status`);
4. nunca assumir que um IP antigo continua sendo produção;
5. preferir o canal GitHub Remote Access documentado em `docs/REMOTE-ACCESS-GITHUB.md` para terminal, serviços e diagnóstico; usar Desktop Commander apenas como fallback opcional para tarefas não-browser; usar SSH/Bastion como fallback autorizado. Navegação/browser segue a regra global abaixo e não usa Windows.

## Navegação e browser — regra global

<!-- GLOBAL_BROWSER_VM_POLICY_V2 -->

O host canônico para navegação e automação browser é `always-free-arm-1787907847-26` (`10.0.1.38`), via Browser Worker privado. Interação humana para MFA/CAPTCHA/consentimento é feita em `https://shopvivaliz.com.br/admin/browser-worker.php`.

Fred-Win (`LAPTOP-NIG4IFUU`) e `DESKTOP-KOCEPSV` **não são destinos nem fallback de navegador**. Se o Browser Worker estiver indisponível, reparar o acesso pela VCN/OCI Bastion/túnel privado; não transferir a navegação para Windows. Exceção somente por ordem explícita do proprietário na tarefa atual.

## Hosts operacionais atuais

| Host | IP | Papel | Desktop Commander |
|---|---:|---|---|
| `shopvivaliz-free-a1` | origin `137.131.149.55`, privado `10.0.1.112` | site/web/deploy de produção | dispositivo `shopvivaliz-free-a1` |
| `always-free-arm-1787907847-26` | privado `10.0.1.38`, sem IP publico | backend, MEI, M365 e relay Fred-Win | dispositivo `always-free-arm-1787907847-26` |
| `shopvivaliz-ai` | `137.131.156.17` | DEV legado / e-mail / testes; **não tratar como produção web** | pode aparecer offline/legado |

A arquitetura atual deve ser confirmada no código e nos hosts antes de qualquer intervenção. Se houver divergência entre este arquivo e evidência ao vivo, pare a hipótese e atualize a documentação com a evidência encontrada.

## Inventário canônico de runtime

Para status operacional, usar `scripts/runtime-service-status.sh` ou a ação remota `runtime_status`, que executa esse inventário. Não montar health checks a partir de nomes históricos memorizados.

Semântica obrigatória:

- `LoadState=not-found` em unidade aposentada significa **ausente como esperado**, não serviço quebrado.
- Serviço `oneshot` pode ficar `inactive (dead)` entre execuções e continuar saudável. Para catálogo, o gate é `shopvivaliz-catalog-reconcile.timer` ativo + último `shopvivaliz-catalog-reconcile.service` com `Result=success` e `ExecMainStatus=0`.
- No backend, `mei-mg-email-worker.service` deve permanecer inativo enquanto `/var/lib/mei-mg-email/sender_blocked.pause` existir. Reiniciar o worker nesse estado é violação do circuit breaker.
- Uso de disco >= 85% é atenção operacional mesmo quando os serviços estão saudáveis.

Runtime principal atual em `shopvivaliz-free-a1`:

```text
apache2.service
shopvivaliz-queue-worker.service
shopvivaliz-token-renewer.service
shopvivaliz-shopee-token-renewer.service
shopvivaliz-catalog-reconcile.timer
shopvivaliz-catalog-reconcile.service   # oneshot; normalmente inactive entre execuções
shopvivaliz-desktop-commander.service
```

Runtime principal atual em `always-free-arm-1787907847-26`:

```text
shopvivaliz-desktop-commander.service
mei-mg-email-api.service
mei-mg-email-monitor.service
mei-mg-email-queue-replenisher.service
mei-mg-email-brevo-reconciler.service
mei-mg-email-site-tunnel.service
mei-mg-email-worker.service             # policy-aware; pode estar intencionalmente inactive
```

Nomes como `shopvivaliz-products-active-sync.service`, `shopvivaliz-24x7.service`, `agent-bridge.service`, `shopvivaliz-mcp.service` e `mei-mg-email.service` não devem ser usados como prova de indisponibilidade do runtime atual quando estiverem `not-found`.

## Produção web/deploy

Diretório operacional:

```text
/home/ubuntu/shopvivaliz-deploy/
```

Estrutura esperada:

```text
repo/       clone de deploy
releases/   releases imutáveis
current -> releases/<release-ativa>
shared/     estado/segredos/runtime persistentes
```

Nunca editar diretamente `current/` nem `releases/<ativa>/`.

## SSH

SSH publico direto esta desabilitado. GitHub Actions administrativos usam o runner `shopvivaliz-a1-deploy`: site por `127.0.0.1` e backend por `10.0.1.38`. Operadores externos usam OCI Bastion ou Remote Desktop Commander.

Usuário padrão das VMs Oracle:

```text
ubuntu
```

A chave privada deve vir de armazenamento local seguro ou secret de automação. **Não versionar chave privada, senha, token ou conteúdo de secret.**

Referências aceitas de credencial:

```text
Windows local: C:\Users\FRED\Downloads\ssh-key-2026-07-04.key
GitHub Actions: ORACLE_VM_SSH_KEY
Ambiente de agente/projeto: SHOPVIVALIZ_VM_SSH_KEY (quando fornecido pelo runtime)
```

Exemplos:

```bash
ssh -i ~/.ssh/id_rsa ubuntu@127.0.0.1  # somente no runner self-hosted do site
ssh -i ~/.ssh/id_rsa ubuntu@10.0.1.38  # somente dentro da VCN
ssh -i ~/.ssh/id_rsa ubuntu@137.131.156.17
```

No Windows:

```powershell
Use Remote Desktop Commander ou OCI Bastion; SSH publico direto esta desabilitado.
```

## GitHub Remote Access (canal primário)

Para operações de terminal, serviços, status, disco e Git nos quatro hosts, usar `docs/REMOTE-ACCESS-GITHUB.md` e `.github/workflows/shopvivaliz-remote-access.yml`. O issue canônico `#1586` dispara a execução por comentário allowlisted e mantém trilha de auditoria por comentário/workflow, sem acionar pipelines gerais de `push`. `ops/remote-access-request.json` é legado temporário e não deve ser usado por novas execuções.

## Desktop Commander (fallback opcional)

Quando houver cota disponível e necessidade específica de UI, o acesso pode ser feito por nome do dispositivo em vez de depender de IP/chave manual:

```text
shopvivaliz-free-a1
always-free-arm-1787907847-26
```

Antes de operar, execute ping/listagem do dispositivo e depois valide:

```bash
hostname
whoami
pwd
```

## Repositório principal

Fonte de verdade versionada:

```text
https://github.com/Vivaliz-site/site-shopvivaliz
branch principal: main
```

Em uma cópia Git válida, confirmar:

```bash
git remote -v
git branch --show-current
git status --porcelain
```

O caminho `/home/ubuntu/shopvivaliz-deploy/repo` pode ser um worktree/clone de deploy. Se o metadata Git estiver quebrado ou apontar para worktree removido, **não force correção destrutiva**: use clone limpo temporário ou GitHub API e preserve o deploy ativo.

## Validação de health

Para Squad Chat, health só é válido se a resposta comprovar simultaneamente:

```text
ok=true
endpoint=squad-chat
providers presente
```

`configured=true` sozinho não prova autenticação do provider.

## Onde esta informação deve aparecer

- `docs/knowledge/host-access.md` — fonte canônica;
- `docs/knowledge/README.md` — índice;
- `AGENTS.md` — regra de bootstrap para qualquer agente;
- `CLAUDE.md` — bootstrap para Claude;
- `README.md` — ponte para a Knowledge Base;
- instruções de projeto/assistente — devem apontar para este documento e para `docs/knowledge/`;
- memória do produto, quando habilitada — guardar apenas o mapa não secreto de hosts e a regra de consultar a Knowledge Base; nunca guardar chave privada ou token.

## Segurança

Este arquivo é público no repositório. Por isso contém somente endereços, papéis e **nomes de secrets/paths**, não o conteúdo de credenciais. Segredos devem permanecer em Secret Manager, GitHub Secrets, arquivo local protegido ou runtime autorizado.
