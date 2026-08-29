# Instruções de acesso à VM de produção para agentes

## Escopo

Este documento define o procedimento oficial para tarefas na VM de produção ShopVivaliz.

- Host: `163.176.103.253`
- Usuário: `ubuntu`
- Diretório operacional: `/home/ubuntu/shopvivaliz-deploy`
- Repositório: `Vivaliz-site/site-shopvivaliz`

## Credenciais

Nunca grave chave privada, token ou conteúdo de secret no repositório, em issues, PRs, artifacts ou logs.

Em GitHub Actions, use exclusivamente um secret protegido:

```yaml
${{ secrets.SHOPVIVALIZ_VM_SSH_KEY }}
```

O pipeline atual também pode usar o nome legado:

```yaml
${{ secrets.ORACLE_VM_SSH_KEY }}
```

Para validação do host, use `SHOPVIVALIZ_VM_KNOWN_HOSTS`, `ORACLE_VM_KNOWN_HOSTS` ou secret equivalente. Nunca desative `StrictHostKeyChecking`.

Em uma estação Windows autorizada, a chave local pode estar em:

```text
C:\Users\FRED\Downloads\ssh-key-2026-07-04.key
```

Esse caminho é apenas referência local. O arquivo nunca deve ser enviado ao chat, commitado ou copiado para documentação.

## Prompt padrão para agentes

```text
Você tem acesso à VM de produção ShopVivaliz (163.176.103.253) via SSH.

Credenciais:
- Host: 163.176.103.253
- Usuário: ubuntu
- Chave no GitHub Actions: ${{ secrets.SHOPVIVALIZ_VM_SSH_KEY }}

Tarefa: <descrever exatamente a tarefa>

Execução:
1. Configure ~/.ssh/id_rsa a partir do secret protegido, com permissão 600.
2. Valide o host por known_hosts e StrictHostKeyChecking=yes.
3. Conecte com:
   ssh -i ~/.ssh/id_rsa ubuntu@163.176.103.253
4. Entre em:
   cd /home/ubuntu/shopvivaliz-deploy
5. Execute somente os comandos necessários.
6. Retorne: comando executado, código de saída, output sanitizado, SHA/release e logs relevantes.
7. Nunca exiba secrets, .env, chaves privadas ou tokens.
```

## Configuração segura em GitHub Actions

```bash
set -Eeuo pipefail

test -n "$SHOPVIVALIZ_VM_SSH_KEY"
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
  ubuntu@163.176.103.253 \
  'cd /home/ubuntu/shopvivaliz-deploy && <COMANDO>'
```

## Acesso local no Windows

```powershell
ssh -i "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key" ubuntu@163.176.103.253
```

Depois da conexão:

```bash
cd /home/ubuntu/shopvivaliz-deploy
```

## Regras obrigatórias

1. Use `set -Eeuo pipefail` em scripts remotos.
2. Não edite `/home/ubuntu/shopvivaliz-deploy/current` nem uma release ativa.
3. Não use `git reset --hard`, `git clean -fd`, force push ou comandos destrutivos.
4. Não leia ou imprima `.env`, chaves privadas, tokens ou secrets.
5. Antes de deploy, registre SHA esperado, release ativa e estado do working tree.
6. Use o lock `/var/lock/shopvivaliz-deploy.lock` para deploy/sync.
7. Deploy deve usar o fluxo canônico de releases imutáveis.
8. Só declare sucesso após validar SHA, sync, endpoint de versão e smoke test.

## Comandos de diagnóstico autorizados

```bash
set -Eeuo pipefail

cd /home/ubuntu/shopvivaliz-deploy

git -C repo status --short
git -C repo branch --show-current
git -C repo rev-parse HEAD
git -C repo rev-parse origin/main

readlink -f current
cat current/.release-sha

cat shared/logs/tri-environment-sync.json
cat shared/logs/deploy-status.json

sudo systemctl status apache2 --no-pager -l
sudo apache2ctl configtest

curl --fail --silent --show-error \
  -H 'Host: shopvivaliz.com.br' \
  http://127.0.0.1/api/health/version.php
```

## Deploy e sincronização

O procedimento preferencial é o workflow `Master Production Pipeline 24/7`, que usa secrets protegidos e gera evidência.

Para execução manual revisada na VM:

```bash
set -Eeuo pipefail
cd /home/ubuntu/shopvivaliz-deploy
expected_sha='<SHA_VALIDADO>'

sudo flock -n /var/lock/shopvivaliz-deploy.lock \
  repo/scripts/deploy-production.sh "$expected_sha"

bash current/scripts/production-smoke-test.sh "$expected_sha"
```

## Evidência mínima de conclusão

O retorno deve conter:

- código de saída de cada comando crítico;
- SHA esperado;
- `repo HEAD` e `origin/main`;
- conteúdo sanitizado de `current/.release-sha`;
- caminho da release ativa;
- resultado do endpoint `/api/health/version.php`;
- resultado do smoke test;
- logs relevantes sem secrets;
- status final: `COMPROVADO`, `FALHOU` ou `INCONCLUSIVO`.

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
