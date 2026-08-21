# Fred-Win Terminal Operations — ShopVivaliz

## Objetivo

Padronizar o uso seguro do terminal Windows autorizado da ShopVivaliz para administrar a VM de produção sem expor credenciais, chaves privadas, tokens ou conteúdo de `.env`.

## Canal aprovado

- Windows host: Fred-Win
- Shell: PowerShell 5.1+
- SSH host alias: `shopvivaliz-vm`
- Usuário remoto esperado: `ubuntu`
- Hostname remoto esperado: `shopvivaliz-ai`
- Deploy web: `/home/ubuntu/shopvivaliz-deploy/current`
- Remetente de e-mail: `/home/ubuntu/mei-mg-email`

A configuração SSH existente deve ser preservada. Não criar portas públicas novas, não alterar firewall/Cloudflare/sshd para facilitar acesso e não copiar chaves privadas para scripts, logs, commits ou chat.

## Pré-checagem local

Executar somente metadados seguros:

```powershell
Get-Command ssh
ssh -V
Get-Service ssh-agent
Get-ChildItem $HOME\.ssh -Force -ErrorAction SilentlyContinue
if (Test-Path $HOME\.ssh\config) { Get-Content $HOME\.ssh\config }
```

Nunca executar `Get-Content` em chaves privadas. Se o `ssh-agent` estiver configurado e parado, iniciar apenas quando isso for compatível com a configuração existente.

## Teste de conexão

```powershell
ssh shopvivaliz-vm "hostname; whoami; pwd"
```

Resultado esperado:

- hostname: `shopvivaliz-ai`
- usuário: `ubuntu`

## Validação do deploy web

```powershell
ssh shopvivaliz-vm "test -d /home/ubuntu/shopvivaliz-deploy/current && echo DEPLOY_OK || echo DEPLOY_NOT_FOUND"
ssh shopvivaliz-vm "git --version; php -r 'echo PHP_VERSION, PHP_EOL;'"
```

O deploy usa releases imutáveis. `current` normalmente não contém `.git`. Para identificar a revisão ativa, usar o alvo do symlink:

```powershell
ssh shopvivaliz-vm "readlink -f /home/ubuntu/shopvivaliz-deploy/current"
```

A release segue o padrão `YYYYMMDD-HHMMSS-<sha_curto>`; o sufixo é o SHA do deploy ativo.

## Testes HTTP seguros

Mostrar apenas status, URL final e tempo total:

```powershell
ssh shopvivaliz-vm "curl -L -sS -o /dev/null --connect-timeout 10 --max-time 30 -w '%{http_code}|%{url_effective}|%{time_total}\n' 'https://shopvivaliz.com.br/'"
```

Repetir para:

- `https://shopvivaliz.com.br/catalogo`
- `https://shopvivaliz.com.br/catalogo?q=antique`
- `https://shopvivaliz.com.br/catalogo?q=decore`

Não imprimir cookies, headers de autenticação ou HTML completo.

## PowerShell 5.1: comandos Bash complexos

O PowerShell 5.1 pode tentar interpretar `&&`, `||`, `$()` e pipes quando um comando Bash é montado em string interpolada. Para comandos remotos maiores, usar here-string literal:

```powershell
$ssh = (Get-Command ssh -ErrorAction Stop).Source
$remote = @'
set -u
hostname
whoami
'@
& $ssh -o BatchMode=yes -o ConnectTimeout=15 shopvivaliz-vm $remote
```

Esse padrão deve ser preferido no relay Fred-Win.

## Relay auditável via GitHub Actions

Workflow canônico:

- `.github/workflows/fred-win-terminal.yml`
- request: `ops/fredwin-terminal-request.json`

O request deve conter apenas comandos e metadados operacionais; nunca secrets. O workflow valida o relay privado antes de executar o PowerShell no Fred-Win.

Exemplo de request sanitizado:

```json
{
  "action": "terminal",
  "shell": "powershell",
  "timeout": 120,
  "command": "$ssh=(Get-Command ssh).Source; & $ssh shopvivaliz-vm 'hostname; whoami'",
  "requested_at": "ISO-8601",
  "reason": "diagnostico operacional"
}
```

## Operação do remetente de e-mail

Diretório:

```text
/home/ubuntu/mei-mg-email
```

Serviços principais:

```text
mei-mg-email-worker.service
mei-mg-email-ndr-guard.service
mei-mg-email-autorepair.timer
```

Sentinela fail-closed:

```text
/var/lib/mei-mg-email/sender_blocked.pause
```

Nunca iniciar/reiniciar o worker quando a sentinela estiver presente sem antes identificar e corrigir a causa.

### Estado mínimo do remetente

```powershell
$remote = @'
cd /home/ubuntu/mei-mg-email
printf 'WORKER='; sudo systemctl is-active mei-mg-email-worker.service || true
printf 'ENABLED='; sudo systemctl is-enabled mei-mg-email-worker.service || true
printf 'TIMER='; sudo systemctl is-active mei-mg-email-autorepair.timer || true
if sudo test -f /var/lib/mei-mg-email/sender_blocked.pause; then echo SENTINEL=present; else echo SENTINEL=absent; fi
'@
ssh shopvivaliz-vm $remote
```

### Contadores seguros

```bash
sudo docker exec mei-mg-email-db psql -U postgres -d mei_mg_email -At -c "select count(*) from mei_email.envios where status::text in ('submitted','enviado') and enviado_em>=now()-interval '24 hours';"
sudo docker exec mei-mg-email-db psql -U postgres -d mei_mg_email -At -c "select count(*) from mei_email.envios where status::text in ('pendente','pending','enviando','processing');"
```

### Regras operacionais de envio

- respeitar a janela móvel de 24h e limites Microsoft;
- taxa efetiva do projeto não deve ultrapassar 10 mensagens/minuto;
- falhar fechado em novo `AS(42004)` / bad outbound sender;
- não reenviar destinatário já submetido/entregue;
- preservar opt-out, hard bounce, complaint/spam/abuse e e-mail inválido;
- bloquear empresa inativa;
- bloquear endereço contendo `contabil`;
- bloquear endereço associado a mais de 2 CNPJs;
- origens como `user_explicit_authorization_2026-08-20`, `user_campaign_authorization_2026-08-20` e variantes de `operator_authorization_true` não constituem, por si só, prova independente de opt-in e devem permanecer rejeitadas pelo runtime salvo evidência auditável de consentimento individual compatível com a política vigente.

## Segurança

Nunca:

- imprimir `.env`;
- imprimir chave privada ou certificado privado;
- copiar token/refresh token/secret para logs;
- usar `set -x` em comandos que possam tocar credenciais;
- commitar secrets;
- desativar firewall, NDR guard, sentinel, throttling ou controles Microsoft;
- matar lock advisory esperado de worker saudável;
- executar mudança destrutiva sem diagnóstico e rollback.

## Critério de terminal pronto

Considerar o terminal operacional quando:

- alias `shopvivaliz-vm` conecta em modo batch;
- host remoto é `shopvivaliz-ai` e usuário `ubuntu`;
- deploy path existe;
- PHP responde;
- URLs críticas retornam HTTP 200 após redirects esperados;
- relay Fred-Win está `READY`.
