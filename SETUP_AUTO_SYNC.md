# Setup de Sincronizacao Automatica

## Uso rapido

```powershell
cd C:\site-shopvivaliz
.\scripts\setup_auto_sync.ps1
```

Isso registra a tarefa `ShopVivaliz Auto Sync` no Task Scheduler para executar
`scripts/local-auto-sync.ps1` a cada 30 minutos.

## O que o script faz hoje

- faz `git fetch origin main`;
- exige que o checkout atual esteja na branch `main`;
- bloqueia execucao se houver arquivos locais modificados;
- bloqueia historico divergente em vez de tentar mergear sozinho;
- faz apenas `pull --ff-only` ou `push` de commits ja existentes;
- grava logs em `logs/local-sync-AAAA-MM-DD.log`.

O script nao cria commits automaticos, nao usa `--no-verify` e nao esconde erro
de Git.

## Comandos uteis

```powershell
.\scripts\setup_auto_sync.ps1 -Status
.\scripts\setup_auto_sync.ps1 -Interval 15
.\scripts\setup_auto_sync.ps1 -Remove
Get-Content logs\local-sync-$(Get-Date -Format 'yyyy-MM-dd').log -Tail 50
```

## Leitura dos codigos de saida

- `0`: sincronizado ou ja em dia.
- `1`: falha inesperada.
- `2`: branch atual nao e `main`.
- `3`: working tree local nao esta limpa.
- `4`: historico local e remoto divergiu.
- `5`: outro agente esta segurando o lock do repositorio.

## Troubleshooting

### A tarefa executa mas nao sincroniza

Veja o resultado mais recente:

```powershell
Get-ScheduledTaskInfo -TaskName "ShopVivaliz Auto Sync"
Get-Content logs\local-sync-$(Get-Date -Format 'yyyy-MM-dd').log -Tail 100
```

### Branch errada

Se o log mostrar codigo `2`, o checkout esta em branch de trabalho. O auto-sync
local so deve rodar em `main`.

### Alteracoes locais

Se o log mostrar codigo `3`, revise:

```powershell
git status --short
```

### Divergencia Git

Se o log mostrar codigo `4`, resolva a integracao manualmente antes de religar o
fluxo automatico.

## Arquivos principais

- `scripts/local-auto-sync.ps1`
- `scripts/auto_sync_git.ps1`
- `scripts/setup_auto_sync.ps1`
- `ATIVAR-AUTO-SYNC.bat`
