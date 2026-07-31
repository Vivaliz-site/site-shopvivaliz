# Prompts operacionais para agentes na VM ShopVivaliz

Este documento define o formato oficial para tarefas que exigem acesso à VM de produção.

## Acesso autorizado

- Host: `137.131.156.17`
- Usuário: `ubuntu`
- Diretório operacional: `/home/ubuntu/shopvivaliz-deploy`
- Chave no GitHub Actions: `${{ secrets.SHOPVIVALIZ_VM_SSH_KEY }}`
- Secret legado compatível no pipeline atual: `${{ secrets.ORACLE_VM_SSH_KEY }}`

Nunca grave a chave privada no repositório, em comentários, artifacts, logs, prompts ou arquivos temporários versionados.

## Prompt padrão

```text
Você tem acesso à VM de produção ShopVivaliz (137.131.156.17) via SSH.

Credenciais:
- Host: 137.131.156.17
- Usuário: ubuntu
- Chave SSH protegida: ${{ secrets.SHOPVIVALIZ_VM_SSH_KEY }}

Tarefa: <descrever objetivo exato>

Execução:
1. Configure a chave em ~/.ssh/id_rsa com permissão 0600.
2. Use StrictHostKeyChecking=yes e o secret de known_hosts.
3. Conecte com: ssh -i ~/.ssh/id_rsa ubuntu@137.131.156.17
4. Execute: cd /home/ubuntu/shopvivaliz-deploy
5. Registre estado antes e depois.
6. Retorne output, código de saída, SHA, release ativa e logs relevantes.
```

## Configuração obrigatória no GitHub Actions

```bash
set -Eeuo pipefail
install -m 700 -d "$HOME/.ssh"
printf '%s\n' "$SHOPVIVALIZ_VM_SSH_KEY" > "$HOME/.ssh/id_rsa"
chmod 600 "$HOME/.ssh/id_rsa"
printf '%s\n' "$SHOPVIVALIZ_VM_KNOWN_HOSTS" > "$HOME/.ssh/known_hosts"
chmod 600 "$HOME/.ssh/known_hosts"

ssh \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts" \
  -i "$HOME/.ssh/id_rsa" \
  ubuntu@137.131.156.17
```

Secrets esperados:

- `SHOPVIVALIZ_VM_SSH_KEY`
- `SHOPVIVALIZ_VM_KNOWN_HOSTS`

Enquanto o pipeline legado ainda usar os nomes antigos, também podem existir:

- `ORACLE_VM_SSH_KEY`
- `ORACLE_VM_KNOWN_HOSTS`

## Checklist antes de executar

1. Confirmar que a tarefa contém um objetivo concreto.
2. Usar `set -Eeuo pipefail`.
3. Não imprimir secrets ou conteúdo de `.env`.
4. Não editar `/home/ubuntu/shopvivaliz-deploy/current` nem `releases/`.
5. Não usar `git reset --hard`, `git clean -f`, force-push ou auto-merge.
6. Respeitar `/var/lock/shopvivaliz-deploy.lock`.
7. Para deploy, usar o pipeline canônico ou `repo/scripts/deploy-production.sh`.
8. Para sync, usar `current/scripts/auto-sync-oracle.sh` ou o runner canônico documentado.
9. Confirmar no final:
   - `git -C /home/ubuntu/shopvivaliz-deploy/repo rev-parse HEAD`
   - `git -C /home/ubuntu/shopvivaliz-deploy/repo rev-parse origin/main`
   - `cat /home/ubuntu/shopvivaliz-deploy/current/.release-sha`
   - `readlink -f /home/ubuntu/shopvivaliz-deploy/current`
   - health/smoke correspondente.

## Formato obrigatório da resposta

```text
STATUS: COMPROVADO | FALHOU | INCONCLUSIVO
EXIT_CODE: <número>
REPO_HEAD: <sha>
ORIGIN_MAIN: <sha>
RELEASE_SHA: <sha>
RELEASE_PATH: <caminho>
OUTPUT: <resumo objetivo>
LOGS: <arquivos ou runs relevantes>
```

Nunca declarar sucesso quando os três SHAs não coincidirem ou quando o smoke test não tiver passado.
