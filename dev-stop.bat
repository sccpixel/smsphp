@echo off
rem Fecha as janelas abertas pelo dev-start.bat (dispatcher e aprovacoes; o Apache fica como esta).
taskkill /F /T /FI "WINDOWTITLE eq sms-dispatcher*" >nul 2>&1
taskkill /F /T /FI "WINDOWTITLE eq sms-approvals*" >nul 2>&1
taskkill /F /T /FI "WINDOWTITLE eq sms-callbacks*" >nul 2>&1
if /i not "%~1"=="quiet" echo Processos de teste parados.
exit /b 0
