@echo off
REM Script rapido para ativar a sincronizacao automatica usando o configurador canonico.

cd /d c:\site-shopvivaliz

echo ========================================================
echo ShopVivaliz Auto Sync
echo ========================================================
echo.
echo Repositorio: C:\site-shopvivaliz
echo Intervalo padrao: 30 minutos
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -File c:\site-shopvivaliz\scripts\setup_auto_sync.ps1 -Interval 30
set "RESULT=%errorlevel%"

echo.
echo ========================================================
echo RESULTADO:
echo ========================================================
if %RESULT% equ 0 (
    echo [OK] Tarefa configurada com sucesso.
    echo [OK] Consulte os logs em logs\local-sync-AAAA-MM-DD.log
    echo [OK] Ver status: powershell.exe -NoProfile -ExecutionPolicy Bypass -File c:\site-shopvivaliz\scripts\setup_auto_sync.ps1 -Status
) else (
    echo [ERRO] Falha ao configurar a tarefa.
    echo [ERRO] Revise a saida acima para identificar o bloqueio.
)

pause
