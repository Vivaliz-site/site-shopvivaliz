# Desktop Commander 24h — Fred-Win e VM

## Objetivo
Manter o Desktop Commander oficial disponível de forma autônoma nos dois hosts, sem depender de terminal aberto, sessão SSH interativa ou logon manual enquanto a sessão persistente aceita pelo provedor continuar válida.

## Fred-Win
- Canal oficial: `@wonderwhy-er/desktop-commander@0.2.47 remote`.
- Perfil persistente: usuário Windows que contém `%USERPROFILE%\.desktop-commander-device\device.json`.
- Supervisor: `scripts/fredwin-desktop-commander-supervisor.ps1`.
- Runner sanitizado: `scripts/fredwin-desktop-commander-runner.ps1`.
- Tarefa: `ShopVivaliz Desktop Commander 24h`.
- Startup: `AtStartup`, `LogonType S4U`, `RunLevel Highest`.
- Watchdog: a cada 1 minuto, `MultipleInstances IgnoreNew`, `StartWhenAvailable`.
- Recovery independente: relay privado GitHub Actions -> Oracle VM -> reverse SSH -> Fred-Win.

O runner descarta a saída bruta do provedor. Se detectar solicitação de device authorization, registra somente `AUTH_REQUIRED`, cria cooldown de 6 horas e evita gerar códigos repetidamente.

## VM Ubuntu
- Canal oficial: mesmo pacote/versão, sob usuário `ubuntu`.
- Perfil persistente: `/home/ubuntu/.desktop-commander-device/device.json`.
- Unit: `shopvivaliz-desktop-commander.service`.
- `HOME=/home/ubuntu`, XDG e npm cache fixos.
- `Restart=always`, `RestartSec=10`, `RestartPreventExitStatus=20`.
- Node/npx são resolvidos sob o usuário `ubuntu` e apenas os caminhos executáveis são gravados em `/etc/default/shopvivaliz-desktop-commander`.
- Recovery independente: SSH + systemd.

## Diagnóstico seguro
Nunca ler ou imprimir o conteúdo de `device.json`. Validar somente existência/mtime, usuário, HOME, estado do processo/tarefa/unit e PID. Nunca persistir token, cookie, device code, session blob, chave privada ou device identifier completo.

## Limite externo de autenticação
A automação mantém processo, perfil, startup e reconexão. Ela não contorna política de autenticação do provedor. Se a sessão externa for revogada/invalidada, o supervisor deve parar loops de device flow e manter o canal de recovery disponível; nova autorização só é necessária quando o próprio provedor exigir.

## Verificação após instalação
Fred-Win: confirmar tarefa existente, processo `desktop-commander ... remote`, device online, matar somente o processo e observar recuperação pelo watchdog sem intervenção.

VM: confirmar `systemctl is-enabled shopvivaliz-desktop-commander.service` = `enabled`, `systemctl is-active ...` = `active`, matar somente o MainPID e confirmar novo MainPID após o `RestartSec`.

A conclusão `24h operacional` só pode ser declarada com evidência fresca dos dois hosts depois desses testes.
