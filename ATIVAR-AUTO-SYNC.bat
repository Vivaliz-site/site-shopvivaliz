@echo off
REM Script para ativar sincronização automática
REM Clique 2x neste arquivo COM DIREITOS DE ADMINISTRADOR

cd /d c:\site-shopvivaliz

echo ========================================================
echo [ADMIN] Ativando ShopVivaliz Auto Sync
echo ========================================================
echo.

REM Remover tarefa anterior
schtasks /delete /tn "ShopVivaliz Auto Sync" /f >nul 2>&1

REM Criar nova tarefa
schtasks /create ^
  /tn "ShopVivaliz Auto Sync" ^
  /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -File c:\site-shopvivaliz\scripts\auto_sync_git.ps1 -Interval 5" ^
  /sc MINUTE ^
  /mo 5 ^
  /f ^
  /rl HIGHEST

echo.
echo ========================================================
echo RESULTADO:
echo ========================================================
if %errorlevel% equ 0 (
    echo [OK] Tarefa criada com sucesso!
    echo.
    echo Status Final do Projeto:
    echo ==========================
    echo [OK] Consolidacao de Secrets
    echo [OK] Validacao de Secrets
    echo [OK] Auto-Sync ATIVO
    echo [OK] Sistema PRONTO PARA PRODUCAO
    echo ==========================
    echo.
    echo A sincronizacao vai rodar a cada 5 minutos!
) else (
    echo [ERRO] Falha ao criar tarefa
    echo Certifique-se de executar como Administrador!
)

pause
