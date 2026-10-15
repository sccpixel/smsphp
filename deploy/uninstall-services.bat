@echo off
rem Remove os servicos sms-dispatcher e sms-approvals (corre como Administrador).
setlocal
set "NSSM=nssm"
if exist "%~dp0nssm.exe" set "NSSM=%~dp0nssm.exe"

net session >nul 2>&1
if errorlevel 1 (
    echo [ERRO] Corre este script como Administrador.
    pause
    exit /b 1
)

for %%S in (sms-dispatcher sms-approvals) do (
    "%NSSM%" status %%S >nul 2>&1
    if not errorlevel 1 (
        echo A remover %%S...
        "%NSSM%" stop %%S >nul 2>&1
        "%NSSM%" remove %%S confirm >nul 2>&1
    ) else (
        echo %%S nao esta instalado.
    )
)
endlocal
