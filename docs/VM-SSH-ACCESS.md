# Acesso SSH à VM de produção (Oracle Cloud)

> ⚠️ **Este arquivo documenta apenas a CHAVE PÚBLICA e o processo de acesso.**
> A chave PRIVADA correspondente nunca é commitada no repositório — ela fica
> apenas localmente na máquina do Fred, em `C:\Users\FRED\Downloads\shopvivaliz_vm_agent`.
> Subir uma chave privada para o Git (mesmo em repositório privado) é
> exatamente o tipo de vazamento de credencial que já aconteceu antes neste
> projeto (Client Secret do Tiny/Olist) — nunca repita esse erro aqui.

## Dados de conexão

| Item | Valor |
|---|---|
| IP da VM | `163.176.103.253` |
| Usuário | `ubuntu` |
| Domínio servido | `dev.shopvivaliz.com.br` (produção real, ver `CLAUDE.md`) |
| Diretório ativo da app na VM | `/home/ubuntu/shopvivaliz-deploy/current` |
| Clone canônico usado pelo deploy | `/home/ubuntu/shopvivaliz-deploy/repo` |
| Chave usada pelo agente | `claude-agent@shopvivaliz-vm-access` (par gerado em 2026-07-26) |

## Chave pública (adicionada ao `~/.ssh/authorized_keys` do usuário `ubuntu` na VM)

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILqHGR4SIOIUTasFDHcDbcdR6YRGQ117ZweMEfKxelWl claude-agent@shopvivaliz-vm-access
```

Se essa chave for revogada/rotacionada no futuro, gere um novo par (não
reaproveite chaves de outros serviços — cada uso deve ter sua própria chave
dedicada) e atualize apenas a linha acima com a nova chave pública, apontando
para onde a nova privada foi salva.

## Onde a chave privada está salva

- **Caminho local histórico:** `C:\Users\FRED\Downloads\shopvivaliz_vm_agent`
- **Chave funcional confirmada nesta máquina em 2026-07-29:** `C:\Users\FRED\Downloads\ssh-key-2026-07-04.key`
- **GitHub Actions Secret:** `SHOPVIVALIZ_VM_SSH_KEY`, no repositório
  `Vivaliz-site/site-shopvivaliz` (configurado em 2026-07-26 via
  `gh secret set`, com autorização explícita do dono do negócio).
- **Não existe e NUNCA deve existir cópia da privada em texto puro em nenhum
  arquivo commitado do repositório, em nenhum branch, em nenhum commit.**
  Subir uma chave privada SSH como arquivo do repo (mesmo em repo privado) é
  o mesmo tipo de vazamento de credencial que já aconteceu antes neste
  projeto (Client Secret do Tiny/Olist) — a diferença é que aqui a
  credencial vazada seria acesso root-equivalente à VM de produção. Isso foi
  pedido explicitamente numa sessão anterior e recusado por esse motivo; o
  Secret do GitHub Actions foi a alternativa acordada com o dono do negócio.

## 🔐 Setup GitHub Secrets (Adicionar/Atualizar Chave)

### Como Adicionar a Chave SSH ao GitHub Secrets

**Via Web (Recomendado):**
1. Acesse: https://github.com/Vivaliz-site/site-shopvivaliz/settings/secrets/actions
2. Clique em **New repository secret**
3. **Name:** `SHOPVIVALIZ_VM_SSH_KEY` (ou `SSH_KEY_PROD_VM` se preferir)
4. **Value:** Cole o CONTEÚDO COMPLETO da chave privada (incluindo `-----BEGIN RSA PRIVATE KEY-----` e `-----END RSA PRIVATE KEY-----`)
5. Clique **Add secret**

**Via CLI (GitHub CLI):**
```bash
gh secret set SHOPVIVALIZ_VM_SSH_KEY < C:\Users\FRED\Downloads\ssh-key-2026-07-04.key
```

**Verificar se Secret está configurado:**
```bash
gh secret list
```

⚠️ **IMPORTANTE:** A chave privada **nunca** deve ser commitada no repositório. Ela só deve existir em:
- GitHub Secrets (para uso em workflows automáticos)
- Máquina local do Fred (para acesso direto via terminal)

### Como usar o Secret (dentro de um GitHub Actions workflow)

O Secret só pode ser lido de dentro de um workflow do GitHub Actions (nunca
copiado para um arquivo do repo). Exemplo de uso num step:

```yaml
- name: Configurar chave SSH da VM
  run: |
    mkdir -p ~/.ssh
    echo "${{ secrets.SHOPVIVALIZ_VM_SSH_KEY }}" > ~/.ssh/shopvivaliz_vm_agent
    chmod 600 ~/.ssh/shopvivaliz_vm_agent
    ssh -o StrictHostKeyChecking=no -i ~/.ssh/shopvivaliz_vm_agent ubuntu@163.176.103.253 "comando aqui"
```

O arquivo criado em `~/.ssh/` existe só dentro do runner efêmero do Actions
(descartado ao fim do job) — nunca é commitado nem persiste.

### Se um agente estiver rodando FORA do GitHub Actions (ex: nesta sessão de chat/Cowork)

Esse tipo de sessão não tem acesso nativo aos Secrets do GitHub Actions (eles
só são injetados dentro de workflows). Nesse caso, o caminho é:
1. Usar a cópia local em `C:\Users\FRED\Downloads\shopvivaliz_vm_agent`, se a
   sessão tiver acesso à máquina do Fred (ex: via um MCP de terminal local); ou
2. Pedir ao Fred para colar o conteúdo da chave temporariamente na conversa
   (sem persistir em arquivo do repo); ou
3. Delegar a ação que precisa da VM para um workflow do GitHub Actions
   (opção mais segura — o agente aciona o workflow via `gh workflow run`, e é
   o workflow que usa o Secret, não a sessão do agente diretamente).

## Como usar (a partir de uma sessão com acesso à máquina do Fred, ex: via Desktop Commander/terminal local)

```powershell
ssh -i C:\Users\FRED\Downloads\ssh-key-2026-07-04.key -o StrictHostKeyChecking=no ubuntu@163.176.103.253 "comando aqui"
```

Toolkit local preferencial quando disponível no terminal:

```powershell
sv-vm-status
sv-vm-ssh hostname
sv-deploy-head -DryRun
sv-deploy-sha <sha> -DryRun
sv-blog-status
sv-blog-publish
```

Para forçar um deploy imediato sem esperar o cron:

```powershell
sv-deploy-head
```

Para fixar produção em um branch/SHA específico e impedir retorno automático para `main`, o alvo persistente fica em:

```text
/home/ubuntu/shopvivaliz-deploy/shared/deploy-target-ref
```

## 🤖 Prompt Padrão para Agentes Autônomos

Use este prompt quando quiser que um agente Claude acesse a VM para executar uma tarefa:

```
Você tem acesso à VM de produção ShopVivaliz via SSH para completar esta tarefa:

**Credenciais e Ambiente:**
- Host: 163.176.103.253
- Usuário: ubuntu
- Chave SSH: Configurada em GitHub Secrets como SHOPVIVALIZ_VM_SSH_KEY
- Diretório de deploy ativo: /home/ubuntu/shopvivaliz-deploy/
- Diretório de código: /home/ubuntu/shopvivaliz-deploy/current/
- Environment vars (produção): /home/ubuntu/shopvivaliz-deploy/shared/.env

**Estrutura de Diretórios:**
```
/home/ubuntu/
├── shopvivaliz-deploy/        ← Deployment ativo (Apache aponta para current/)
│   ├── repo/                  ← Clone git sincronizado
│   ├── releases/              ← Releases imutáveis (timestamped)
│   ├── current/               ← Symlink para release ativa
│   └── shared/.env            ← Environment real (NUNCA sobrescrever!)
│
└── site-shopvivaliz/          ← Checkout separado para daemons
    ├── .env                   ← Environment para daemons
    └── logs/                  ← Arquivos de log
```

**Tarefa:** [DESCRIÇÃO AQUI]

**Passos para executar:**
1. Conecte via SSH:
   ssh -i ~/.ssh/id_rsa ubuntu@163.176.103.253

2. Navegue para o diretório apropriado:
   - Deploy ativo: cd /home/ubuntu/shopvivaliz-deploy/current
   - Daemons: cd /home/ubuntu/site-shopvivaliz

3. Execute a tarefa: [COMANDO AQUI]

4. Retorne:
   - Output completo do comando
   - Status final (sucesso/falha)
   - Logs relevantes se houver erro
   - Tempo de execução

**Comandos Úteis:**
- Ver status do deploy: ls -lha /home/ubuntu/shopvivaliz-deploy/current
- Ver logs: tail -f /home/ubuntu/site-shopvivaliz/logs/*.log
- Forçar deploy imediato: sudo /usr/local/lib/shopvivaliz/deploy-production.sh
- Reiniciar serviços: sudo systemctl restart shopvivaliz-24x7
- Verificar espaço: df -h

**Cuidados:**
- NÃO edite /home/ubuntu/shopvivaliz-deploy/shared/.env (ambiente real!)
- Use sudo apenas para operações de deploy/serviços
- Confirme qualquer comando potencialmente destrutivo antes de executar
- Verifique logs após qualquer mudança
```

---

## Histórico / troubleshooting já mapeado

- Havia chaves antigas em `C:\Users\FRED\.ssh\claude_shopvivaliz` e várias em
  `C:\Users\FRED\Downloads\` (`id_rsa`, `ssh-key-2026-07-04.key`, arquivos
  `.pem`) que **não** estavam autorizadas nesta VM — todas falhavam com
  `Permission denied` (exit 255) mesmo com a rede/porta 22 acessível
  (confirmado via `Test-NetConnection -Port 22`, que retornou sucesso). Não
  presuma que uma chave achada solta na máquina serve para esta VM sem
  confirmar que a pública dela está de fato no `authorized_keys` remoto.
- O cliente OpenSSH do Windows (`ssh.exe`/`ssh-keygen.exe`), quando executado
  via automação remota não-interativa (ex: MCP de terminal), às vezes não
  produz NENHUMA saída em stdout/stderr mesmo em caso de falha real
  (confirmado repetidamente nesta investigação, inclusive com redirecionamento
  explícito via `Start-Process -RedirectStandardError`). Se isso acontecer de
  novo, não assuma rede quebrada — teste a porta separadamente
  (`Test-NetConnection -ComputerName 163.176.103.253 -Port 22`) antes de
  investigar mais. Para gerar novas chaves de forma confiável nesse ambiente,
  use Python (`cryptography.hazmat.primitives.asymmetric.ed25519`) em vez de
  `ssh-keygen.exe`, que apresentou o mesmo problema de saída silenciosa.
