# Auto Sync PC / GitHub / Oracle

GitHub é a fonte da verdade.

## Fluxo

- PC com alterações locais: cria branch `auto/pc-*`, faz commit, push e abre PR draft.
- Oracle com alterações locais: cria branch `auto/oracle-*`, faz commit, push e abre PR draft.
- Sem alterações locais: executa `git pull --ff-only origin main`.
- Nunca força push.
- Nunca faz reset hard automático.
- Nunca envia direto para main.

## Instalar no PC Windows

```powershell
powershell -ExecutionPolicy Bypass -File scripts\install-auto-sync-pc.ps1
```
### Rodar manualmente no PC
```powershell
powershell -ExecutionPolicy Bypass -File scripts\auto-sync-pc.ps1
```
### Instalar no Oracle
```bash
bash scripts/install-auto-sync-oracle.sh
```
### Rodar manualmente no Oracle
```bash
bash scripts/auto-sync-oracle.sh
```
## Logs

### PC:

`logs/auto-sync-pc.log`

### Oracle:

`logs/auto-sync-oracle.log`
