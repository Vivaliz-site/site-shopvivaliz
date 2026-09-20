# Backend Browser Worker

## Objetivo

Executar testes e automações de navegador na VM `always-free-arm-1787907847-26` sem depender de Fred-Win, KOCEPSV ou Desktop Commander.

## Arquitetura

```text
agente / CI
  -> GitHub Remote Access ou browser worker
  -> always-free-arm-1787907847-26
     -> Xvfb :98
     -> Chromium ARM64 via Playwright
     -> worker HTTP somente em 127.0.0.1:17777
     -> reverse SSH tunnel privado
  -> shopvivaliz-free-a1:127.0.0.1:17777
  -> /admin/browser-worker.php
     -> autenticação admin existente + CSRF
```

O worker nunca deve escutar em `0.0.0.0` e o reverse tunnel deve permanecer preso a `127.0.0.1` na VM web. Não expor CDP, VNC ou a porta 17777 diretamente na internet.

## Sessões

Cada sessão registra:

- identificador;
- origem/agente;
- perfil persistente quando aplicável;
- horário de início;
- última atividade;
- expiração;
- PID do worker.

O TTL padrão é de 2 horas. O watchdog interno fecha sessões expiradas. Sessões persistentes mantêm cookies somente no perfil dedicado sob `/home/ubuntu/.local/share/shopvivaliz-browser-worker/profiles`.

## Operação humana / MFA

A interface autenticada é:

```text
https://shopvivaliz.com.br/admin/browser-worker.php
```

Ela permite:

- criar sessão temporária ou persistente;
- visualizar screenshot atualizado do Chromium real;
- clicar diretamente na captura;
- digitar no elemento ativo;
- usar Tab, Enter, Esc e Backspace;
- rolar a página;
- navegar para outra URL;
- renovar a sessão por 2 horas;
- encerrar a sessão.

Essa interface deve ser usada somente quando uma interação humana for realmente necessária, como CAPTCHA, consentimento novo, recovery ou MFA que não possa ser resolvido pelos mecanismos seguros existentes.

## Runtime no backend

Diretório:

```text
/home/ubuntu/shopvivaliz-browser-worker
```

Comandos:

```bash
/home/ubuntu/shopvivaliz-browser-worker/supervisor.sh status
/home/ubuntu/shopvivaliz-browser-worker/supervisor.sh ensure
/home/ubuntu/shopvivaliz-browser-worker/supervisor.sh restart
/home/ubuntu/shopvivaliz-browser-worker/supervisor.sh stop
```

Health local:

```bash
curl -fsS http://127.0.0.1:17777/health
```

Health válido exige `ok=true` e `endpoint=browser-worker`.

## Rota sem Desktop Commander

O workflow `.github/workflows/backend-browser-worker-control.yml` acessa o backend pela rede privada a partir do runner canônico e aceita somente ações allowlisted.

No issue operacional `#1586`:

```text
/browser-vm action=status reason=verificar navegador da VM
/browser-vm action=health reason=validar browser worker
/browser-vm action=install reason=instalar ou reparar browser worker
/browser-vm action=restart reason=reiniciar browser worker
```

Ações aceitas: `status`, `health`, `install`, `start`, `restart` e `stop`.

Se o runner privado estiver indisponível, a rota de emergência continua sendo OCI Bastion, conforme `docs/REMOTE-ACCESS-GITHUB.md`; não habilitar SSH público direto.

## Persistência

O instalador `scripts/install-backend-browser-worker.sh`:

- valida hostname e usuário;
- reutiliza o Chromium ARM64 existente do Playwright;
- instala somente `playwright-core` na versão compatível;
- valida bibliotecas compartilhadas;
- registra `@reboot` e watchdog a cada 5 minutos no crontab do usuário `ubuntu`;
- inicia Xvfb, worker e reverse tunnel;
- valida health local e remoto.

## Segurança

- nenhuma senha, cookie, OTP, seed TOTP ou token deve ser registrada em Git ou log;
- perfis persistentes ficam com permissão privada do usuário;
- screenshot e ações manuais passam pela autenticação administrativa existente;
- o worker não oferece `evaluate` arbitrário;
- o workflow remoto não aceita shell fornecido pelo usuário;
- sessões abandonadas expiram automaticamente.

## Rota OCI hospedada — sem Desktop Commander e sem runner privado

Quando o Desktop Commander estiver indisponível **ou** o runner privado `shopvivaliz-a1-deploy` não puder ser usado, existe um segundo control plane independente:

```text
GitHub-hosted ubuntu-latest
  -> OCI Bastion temporário
  -> SSH privado 10.0.1.38
  -> always-free-arm-1787907847-26
```

Workflow canônico:

```text
.github/workflows/backend-vm-oci-control.yml
```

No issue operacional `#1586`:

```text
/backend-oci action=identity reason=validar acesso independente
/backend-oci action=disk reason=verificar disco do backend
/backend-oci action=runtime_status reason=verificar runtime do backend
/backend-oci action=repo_status reason=verificar repositorios do backend
/backend-oci action=browser_status reason=verificar supervisor do navegador
/backend-oci action=browser_health reason=validar browser worker
/backend-oci action=browser_install reason=instalar ou reparar browser worker
/backend-oci action=browser_start reason=iniciar browser worker
/backend-oci action=browser_restart reason=reiniciar browser worker
/backend-oci action=browser_stop reason=parar browser worker
```

Esse workflow não aceita shell arbitrário. Ele resolve a instância exata `always-free-arm-1787907847-26`, exige o IP privado `10.0.1.38`, abre uma sessão OCI Bastion temporária, valida `hostname/user` antes da ação e restaura a allowlist original do Bastion em modo fail-closed. Credenciais temporárias são apagadas ao final.

A rota foi validada de ponta a ponta com `identity` e `browser_health`, incluindo `BASTION_ALLOWLIST_RESTORED=PASS`.
