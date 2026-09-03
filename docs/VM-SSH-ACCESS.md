# Acesso SSH à VM de produção (Oracle Cloud)

> ⚠️ **Este arquivo documenta o processo de acesso, sem reproduzir material de chave.**
> A chave privada correspondente nunca é commitada no repositório — ela fica
> apenas localmente na máquina autorizada do Fred e em GitHub Secrets quando
> necessária por workflows.

## Dados de conexão

| Item | Valor |
|---|---|
| IP da VM | `163.176.103.253` |
| Usuário | `ubuntu` |
| Domínio servido | `shopvivaliz.com.br` (produção real; `dev.shopvivaliz.com.br` é host legado/inativo para dependências web) |
| Diretório ativo da app na VM | `/home/ubuntu/shopvivaliz-deploy/current` |
| Clone canônico usado pelo deploy | `/home/ubuntu/shopvivaliz-deploy/repo` |
| Identificador da chave do agente | `claude-agent@shopvivaliz-vm-access` |

## Material de chave

A chave pública autorizada é gerenciada diretamente no `~/.ssh/authorized_keys`
do usuário `ubuntu` e não é reproduzida neste repositório. A chave privada
nunca deve ser colocada em arquivos versionados, comentários, issues, PRs ou
mensagens de chat.

Se a chave for revogada/rotacionada, gere um novo par dedicado, atualize o
`authorized_keys` remoto e o Secret correspondente, e registre aqui apenas o
identificador e a data da rotação.

## Onde a chave privada é gerenciada

- Máquina local autorizada do Fred.
- GitHub Actions Secret: `SHOPVIVALIZ_VM_SSH_KEY`, no repositório
  `Vivaliz-site/site-shopvivaliz`.
- Não deve existir cópia da privada em texto puro em nenhum arquivo commitado.

## 🔐 Setup GitHub Secrets

### Adicionar/atualizar a chave SSH

**Via Web:**
1. Acesse as configurações de Secrets do repositório.
2. Crie/atualize `SHOPVIVALIZ_VM_SSH_KEY`.
3. Cole o conteúdo completo da chave privada diretamente no campo protegido.
4. Salve o Secret.

**Via CLI:**
```bash
gh secret set SHOPVIVALIZ_VM_SSH_KEY < CAMINHO_LOCAL_DA_CHAVE
```

**Verificar se o Secret está configurado:**
```bash
gh secret list
```

### Como usar o Secret em GitHub Actions

```yaml
- name: Configurar chave SSH da VM
  run: |
    mkdir -p ~/.ssh
    printf '%s\n' "${{ secrets.SHOPVIVALIZ_VM_SSH_KEY }}" > ~/.ssh/shopvivaliz_vm_agent
    chmod 600 ~/.ssh/shopvivaliz_vm_agent
    ssh -o StrictHostKeyChecking=no -i ~/.ssh/shopvivaliz_vm_agent ubuntu@163.176.103.253 "comando aqui"
```

O arquivo criado no runner é efêmero e deve ser descartado ao fim do job.

### Sessões fora do GitHub Actions

Quando a sessão tiver acesso autorizado à máquina local do Fred, use a chave
privada local sem copiá-la para o repositório ou para a conversa. Caso a sessão
não tenha esse acesso, delegue a operação para um workflow autorizado que use o
Secret do GitHub Actions.

## Como usar a partir de uma sessão autorizada

```powershell
ssh -i CAMINHO_LOCAL_DA_CHAVE -o StrictHostKeyChecking=no ubuntu@163.176.103.253 "comando aqui"
```

Toolkit local preferencial quando disponível:

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

## 🤖 Prompt padrão para agentes autônomos

Use este contexto quando um agente autorizado precisar acessar a VM:

```text
VM de produção ShopVivaliz:
- Host: 163.176.103.253
- Usuário: ubuntu
- Chave SSH: GitHub Secret SHOPVIVALIZ_VM_SSH_KEY ou chave local autorizada
- Diretório de deploy ativo: /home/ubuntu/shopvivaliz-deploy/current/
- Environment de produção: /home/ubuntu/shopvivaliz-deploy/shared/.env

Regras:
1. Não editar /home/ubuntu/shopvivaliz-deploy/shared/.env sem necessidade explícita.
2. Usar sudo apenas para deploy/serviços.
3. Validar logs e estado final após mudanças.
4. Nunca copiar material de chave para o repositório ou conversa.
```

## Histórico / troubleshooting já mapeado

- Não presuma que uma chave encontrada localmente está autorizada na VM; valide
  o acesso sem copiar material de chave para logs ou documentos.
- Se o cliente OpenSSH automatizado não produzir saída útil, valide primeiro a
  conectividade da porta 22 separadamente antes de concluir que a rede está
  indisponível.
- O domínio web canônico de produção é `shopvivaliz.com.br`; o host
  `dev.shopvivaliz.com.br` é legado e não deve ser usado por scripts, agentes,
  health checks ou runbooks como dependência web.
