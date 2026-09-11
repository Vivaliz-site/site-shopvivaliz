# Acesso a hosts — bootstrap obrigatório para agentes

Este documento é a referência canônica de **como localizar e acessar os hosts ShopVivaliz** ao iniciar uma conversa, sessão de IDE ou agente novo.

## Regra zero

Antes de diagnosticar, alterar ou validar qualquer ambiente, o agente deve:

1. ler `docs/knowledge/README.md` e `docs/knowledge/agent-rules.md`;
2. identificar o host correto pelo papel atual;
3. confirmar o acesso com evidência (`hostname`, `whoami`, diretório e, quando aplicável, `git status`);
4. nunca assumir que um IP antigo continua sendo produção;
5. preferir Desktop Commander quando o dispositivo estiver conectado; usar SSH como fallback autorizado.

## Hosts operacionais atuais

| Host | IP | Papel | Desktop Commander |
|---|---:|---|---|
| `shopvivaliz-free-a1` | `163.176.103.253` | site/web/deploy de produção | dispositivo `shopvivaliz-free-a1` |
| `always-free-arm-1787907847-26` | `144.22.157.209` | backend, MEI, M365 e relay Fred-Win | dispositivo `always-free-arm-1787907847-26` |
| `shopvivaliz-ai` | `137.131.156.17` | DEV legado / e-mail / testes; **não tratar como produção web** | pode aparecer offline/legado |

A arquitetura atual deve ser confirmada no código e nos hosts antes de qualquer intervenção. Se houver divergência entre este arquivo e evidência ao vivo, pare a hipótese e atualize a documentação com a evidência encontrada.

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
ssh -i ~/.ssh/id_rsa ubuntu@163.176.103.253
ssh -i ~/.ssh/id_rsa ubuntu@144.22.157.209
ssh -i ~/.ssh/id_rsa ubuntu@137.131.156.17
```

No Windows:

```powershell
ssh -i "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key" ubuntu@163.176.103.253
```

## Desktop Commander

Quando houver dispositivos conectados, preferir o acesso por nome do dispositivo em vez de depender de IP/chave manual:

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
