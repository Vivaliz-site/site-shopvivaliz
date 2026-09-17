# Acesso a hosts — bootstrap obrigatório para agentes

Este documento é a referência canônica de **como localizar e acessar os hosts ShopVivaliz** ao iniciar uma conversa, sessão de IDE ou agente novo.

## Regra zero

Antes de diagnosticar, alterar ou validar qualquer ambiente, o agente deve:

1. ler `docs/knowledge/README.md` e `docs/knowledge/agent-rules.md`;
2. identificar o host correto pelo papel atual;
3. confirmar o acesso com evidência (`hostname`, `whoami`, diretório e, quando aplicável, `git status`);
4. nunca assumir que um IP antigo continua sendo produção;
5. seguir a ordem canônica de acesso abaixo e nunca transformar indisponibilidade do Desktop Commander em indisponibilidade do host.

## Ordem canônica de acesso

1. **GitHub Actions/private relay** — primeira opção para automação, health e ações allowlisted.
2. **OCI Bastion** — usar quando for necessário shell operacional direto em host OCI.
3. **Desktop Commander fallback** — usar somente quando quota/provider estiverem disponíveis e quando esse transporte for realmente necessário.

O Desktop Commander não é mais o plano de controle primário da ShopVivaliz. Falha de quota, provider disconnect ou `AUTH_REQUIRED` do DC não significa, por si só, que o host esteja indisponível.

## Hosts operacionais atuais

| Host | IP | Papel | Controle primário |
|---|---:|---|---|
| `shopvivaliz-free-a1` | origin `137.131.149.55`, privado `10.0.1.112` | site/web/deploy de produção | runner `shopvivaliz-a1-deploy` / GitHub Actions |
| `always-free-arm-1787907847-26` | privado `10.0.1.38`, sem IP público | backend, MEI, M365 e relays Windows | GitHub Actions + SSH privado |
| `LAPTOP-NIG4IFUU` | sem IP público canônico | Fred-Win | backend A1 -> `127.0.0.1:5557` -> reverse SSH -> Windows |
| `DESKTOP-KOCEPSV` | sem IP público canônico | desktop Windows | backend A1 -> `127.0.0.1:5558` -> reverse SSH -> Windows |
| `shopvivaliz-ai` | `137.131.156.17` | DEV legado / e-mail / testes; **não tratar como produção web** | legado |

A arquitetura atual deve ser confirmada no código e nos hosts antes de qualquer intervenção. Se houver divergência entre este arquivo e evidência ao vivo, trate o estado como inconclusivo e atualize a documentação com a evidência encontrada.

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

## Administração das duas VMs OCI

O caminho versionado e allowlisted é `.github/workflows/remote-host-action.yml` com request em `ops/remote-host-request.json`.

Targets aceitos:

```text
shopvivaliz-free-a1
always-free-arm-1787907847-26
```

Ações aceitas:

```text
identity
repo_status
```

`shopvivaliz-free-a1` é administrado localmente pelo runner `shopvivaliz-a1-deploy`. `always-free-arm-1787907847-26` é alcançado por SSH privado em `10.0.1.38` com `StrictHostKeyChecking=yes`. Nenhuma dessas ações deve aceitar comando arbitrário ou editar `/home/ubuntu/shopvivaliz-deploy/current`.

## Relays Windows privados

### Fred-Win

Rota canônica:

```text
GitHub Actions -> shopvivaliz-a1-deploy -> SSH privado 10.0.1.38 -> 127.0.0.1:5557 -> reverse SSH -> LAPTOP-NIG4IFUU
```

Health esperado:

```text
status=ok
environment=fred-win
mcp_version=<não vazio>
```

Referência: `docs/FRED-WIN-PRIVATE-RELAY.md`.

### DESKTOP-KOCEPSV

Rota canônica:

```text
GitHub Actions -> shopvivaliz-a1-deploy -> SSH privado 10.0.1.38 -> 127.0.0.1:5558 -> reverse SSH -> DESKTOP-KOCEPSV
```

Health esperado:

```text
status=ok
environment=desktop-kocepsv
mcp_version=<não vazio>
```

Referência: `docs/DESKTOP-KOCEPSV-PRIVATE-RELAY.md`.

Os relays são privados. Não reativar `trycloudflare.com`, não expor MCP em `0.0.0.0` e não substituir reverse SSH privado por endpoint público.

## SSH e OCI Bastion

SSH público direto está desabilitado para os hosts de produção. GitHub Actions administrativos usam o runner `shopvivaliz-a1-deploy`: site localmente e backend por `10.0.1.38`. Operadores externos usam **OCI Bastion** quando shell direto for necessário.

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

No runner/VCN:

```bash
ssh -i ~/.ssh/id_rsa ubuntu@10.0.1.38
```

No Windows, quando shell OCI for necessário, usar OCI Bastion. Não reabilitar SSH público direto apenas para facilitar operação.

## Desktop Commander fallback

O Desktop Commander continua suportado como transporte de fallback e possui monitor próprio de provider. Ele não é a fonte autoritativa de reachability dos hosts.

Dispositivos conhecidos podem incluir:

```text
shopvivaliz-free-a1
always-free-arm-1787907847-26
LAPTOP-NIG4IFUU
DESKTOP-KOCEPSV
```

Se for utilizado, validar o dispositivo e depois executar evidência básica:

```bash
hostname
whoami
pwd
```

Quota esgotada, `AUTH_REQUIRED` ou provider desconectado devem ser classificados como problema do transporte DC, não como prova de host offline.

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

Para o novo plano de controle remoto, o health autoritativo é `.github/workflows/remote-control-plane-health.yml`. Ele não deve inspecionar `PROVIDER_CONNECTED`, `AUTH_REQUIRED` nem quota do Desktop Commander para decidir reachability.

## Onde esta informação deve aparecer

- `docs/knowledge/host-access.md` — fonte canônica;
- `docs/knowledge/README.md` — índice;
- `docs/FRED-WIN-PRIVATE-RELAY.md` — contrato do relay 5557;
- `docs/DESKTOP-KOCEPSV-PRIVATE-RELAY.md` — contrato do relay 5558;
- `docs/DESKTOP-COMMANDER-24H.md` — saúde do transporte/provider DC como fallback;
- `AGENTS.md` — regra de bootstrap para qualquer agente;
- `CLAUDE.md` — bootstrap para Claude;
- `README.md` — ponte para a Knowledge Base.

## Segurança

Este arquivo é público no repositório. Por isso contém somente endereços, papéis e **nomes de secrets/paths**, não o conteúdo de credenciais. Segredos devem permanecer em Secret Manager, GitHub Secrets, arquivo local protegido ou runtime autorizado.
