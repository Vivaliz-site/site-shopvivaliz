# Git Auto Sync Seguro — ShopVivaliz

## Objetivo

Manter dois PCs sincronizados com o GitHub sem perder alterações locais.

## Regras

- GitHub é a fonte principal.
- Nunca usar `git push --force`.
- Usar `git pull --rebase`.
- Criar backup antes de `pull --rebase`.
- Se houver alterações locais, bloquear pull automático.
- Em conflito, parar e pedir revisão humana.
- Não subir `.env`, `node_modules`, `vendor`, `storage`, `uploads`, `dist`, `reports`, `test-results`, `.claude`, `.codex`.

## Instalação em cada PC

Na raiz do projeto:

```powershell
cd C:\Users\FRED\site-shopvivaliz
powershell -ExecutionPolicy Bypass -File .\scripts\install-git-auto-sync.ps1
```

Opcional para criar task no VS Code:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install-git-auto-sync.ps1 -InstallVSCodeTask
```

## Sincronizar com segurança

Dry-run:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\git-auto-sync.ps1
```

Aplicar pull seguro:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\git-auto-sync.ps1 -Apply
```

Permitir autostash:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\git-auto-sync.ps1 -Apply -AutoStash
```

## Fluxo diário

1. Abrir VS Code.
2. Rodar a task `Git Auto Sync Safe Pull`.
3. Trabalhar normalmente.
4. Fazer commit.
5. O hook `post-commit` envia automaticamente para a branch atual.
6. Abrir PR para `main`.

## Proteção da branch main no GitHub

Configurar manualmente em GitHub > Settings > Branches:

- Require pull request before merging.
- Require status checks to pass.
- Block force pushes.
- Block deletions.
- Require conversation resolution.
- Ativar secret scanning e push protection.
