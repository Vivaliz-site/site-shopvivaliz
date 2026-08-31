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

## DESKTOP-KOCEPSV
- Canal oficial fixado: `@wonderwhy-er/desktop-commander@0.2.47 remote --persist-session`.
- Fontes versionadas: `scripts/desktopkocepsv-desktop-commander-*.ps1` e `scripts/patch-desktop-commander-session-persistence.mjs`.
- Instalação operacional privada: `%LOCALAPPDATA%\ShopVivaliz\DesktopCommander`.
- Tarefa: `ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h`.
- Startup/watchdog: `AtStartup` e a cada 1 minuto, `S4U`, `Highest`, `Hidden`, `WakeToRun`, `IgnoreNew`.
- A ação da tarefa aponta somente para a cópia operacional privada; o serviço não depende de um working tree limpo nem de arquivos não commitados para continuar rodando.
- A ACL da instalação e de `device.json` permite somente o usuário proprietário, `SYSTEM` e administradores locais.
- O runner lê stdout/stderr por pipes em memória e registra apenas transições sanitizadas. Não cria arquivos `shopvivaliz-dc-*.out/.err`.
- Depois da primeira resolução pelo `npx`, o caminho do pacote é validado por nome/versão e guardado em `package-root.txt`; reinícios seguintes usam esse hint privado e só voltam ao `npx` se o cache tiver sido removido ou não passar na validação.
- O log operacional fica em `%LOCALAPPDATA%\ShopVivaliz\DesktopCommander\logs`, rotacionado em 5 MiB com uma geração anterior.
- O monitor renova um marcador a cada 30 segundos depois de `Device ready`. Marcador vencido, processo duplicado ou canal degradado sem recuperação convergem para reinício único.
- Um mutex global recusa supervisores concorrentes mesmo quando a chamada não veio do Agendador; a tarefa continua usando `IgnoreNew` como segunda barreira.
- A tarefa habilita o log operacional `Microsoft-Windows-TaskScheduler/Operational` quando a política local permite.

### Persistência de renovação de sessão
O pacote 0.2.47 reautoriza o canal em memória quando recebe `TOKEN_REFRESHED`, mas não grava automaticamente a sessão rotacionada no arquivo usado pelo próximo boot. O patch local adiciona essa gravação e emite somente o marcador seguro `SESSION_REFRESH_PERSIST_ATTEMPTED`. O runner confirma a alteração por `mtime` de `device.json`, sem ler ou imprimir seu conteúdo, e registra `SESSION_REFRESH_PERSISTED=true`.

### Instalação e diagnóstico
```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\site-shopvivaliz\scripts\desktopkocepsv-desktop-commander-supervisor.ps1 -Mode InstallTask
powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\site-shopvivaliz\scripts\desktopkocepsv-desktop-commander-status.ps1
```

O diagnóstico esperado inclui `CANONICAL_AGENT_COUNT=1`, `NONCANONICAL_AGENT_COUNT=0`, `MONITOR_HEALTHY=True`, `TASK_ACTION_SECURE=True`, `INSTALL_ROOT_ACL_PRIVATE=True`, `DEVICE_STATE_ACL_PRIVATE=True` e `LEGACY_RAW_CAPTURE_COUNT=0`.

## VM Ubuntu
- Canal oficial: mesmo pacote/versão, sob usuário `ubuntu`.
- Perfil persistente: `/home/ubuntu/.desktop-commander-device/device.json`.
- Unit: `shopvivaliz-desktop-commander.service`.
- `HOME=/home/ubuntu`, XDG e npm cache fixos.
- `Restart=always`, `RestartSec=10`, `RestartPreventExitStatus=20`.
- Node/npx são resolvidos sob o usuário `ubuntu` e apenas os caminhos executáveis são gravados em `/etc/default/shopvivaliz-desktop-commander`.
- Recovery independente: SSH + systemd.

## Diagnóstico seguro
Nunca ler ou imprimir o conteúdo de `device.json`. Validar somente existência/mtime, ACL, usuário, HOME, estado do processo/tarefa/unit e PID. Nunca persistir token, cookie, device code, session blob, chave privada ou device identifier completo.

## Limite externo de autenticação
A automação mantém processo, perfil, startup e reconexão. Ela não contorna política de autenticação do provedor. Se a sessão externa for revogada/invalidada, o supervisor deve parar loops de device flow e manter o canal de recovery disponível; nova autorização só é necessária quando o próprio provedor exigir.

## Verificação após instalação
Fred-Win: confirmar tarefa existente, processo `desktop-commander ... remote`, device online, matar somente o processo e observar recuperação pelo watchdog sem intervenção.

VM: confirmar `systemctl is-enabled shopvivaliz-desktop-commander.service` = `enabled`, `systemctl is-active ...` = `active`, matar somente o MainPID e confirmar novo MainPID após o `RestartSec`.

A conclusão `24h operacional` só pode ser declarada com evidência fresca dos dois hosts depois desses testes.
