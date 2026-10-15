@echo off
rem ====================================================================
rem  Instala os 2 workers como servicos do Windows (via NSSM), para
rem  arrancarem sozinhos com o PC e reiniciarem se falharem:
rem    sms-dispatcher   ->  php bin\dispatcher.php   (sms_queue -> sms_out)
rem    sms-approvals    ->  php bin\approvals.php    (le o sms_in, trata as respostas)
rem    sms-callbacks    ->  php bin\callbacks.php    (avisa as APPs quando um pedido e decidido)
rem
rem  Requisitos:
rem    * correr como ADMINISTRADOR (botao direito -> Executar como administrador)
rem    * nssm.exe no PATH, ou na pasta deploy\ (download: https://nssm.cc)
rem    * o .env de producao ja preenchido
rem
rem  Uso:  deploy\install-services.bat
rem ====================================================================
setlocal
cd /d "%~dp0.."
set "ROOT=%CD%"

set "PHP=C:\xampp\php\php.exe"
set "NSSM=nssm"
if exist "%~dp0nssm.exe" set "NSSM=%~dp0nssm.exe"

net session >nul 2>&1
if errorlevel 1 (
    echo [ERRO] Corre este script como Administrador.
    pause
    exit /b 1
)
if not exist "%PHP%" (
    echo [ERRO] PHP nao encontrado em %PHP%. Ajusta a variavel PHP no topo do script.
    pause
    exit /b 1
)
if not exist "%ROOT%\.env" (
    echo [ERRO] Falta o ficheiro .env em %ROOT%
    pause
    exit /b 1
)
if /i "%NSSM%"=="nssm" (
    where nssm >nul 2>&1
    if errorlevel 1 (
        echo [ERRO] nssm nao encontrado. Poe o nssm.exe na pasta deploy\ ou no PATH.
        pause
        exit /b 1
    )
)

if not exist "%ROOT%\logs" mkdir "%ROOT%\logs"

call :install sms-dispatcher "bin\dispatcher.php" "Passa os SMS de sms_queue para sms_out"
call :install sms-approvals  "bin\approvals.php"  "Le as respostas dos gestores e trata os pedidos de aprovacao"
call :install sms-callbacks  "bin\callbacks.php"  "Avisa as APPs (callback) quando um pedido de aprovacao e decidido"

echo.
echo Servicos instalados e iniciados. Estado:
sc query sms-dispatcher | findstr /i "STATE"
sc query sms-approvals  | findstr /i "STATE"
sc query sms-callbacks  | findstr /i "STATE"
echo.
echo Logs dos servicos: %ROOT%\logs\  (e a aplicacao regista em logs\app-AAAA-MM-DD.log)
endlocal
exit /b 0

:install
rem %1 = nome do servico, %2 = script PHP (relativo ao projeto), %3 = descricao
set "SVC=%~1"
"%NSSM%" status %SVC% >nul 2>&1
if not errorlevel 1 (
    echo Servico %SVC% ja existe: a atualizar a configuracao.
    "%NSSM%" stop %SVC% >nul 2>&1
    "%NSSM%" set %SVC% Application "%PHP%" >nul
    "%NSSM%" set %SVC% AppParameters "%ROOT%\%~2" >nul
) else (
    echo A instalar %SVC%...
    "%NSSM%" install %SVC% "%PHP%" "%ROOT%\%~2" >nul
)
"%NSSM%" set %SVC% AppDirectory "%ROOT%" >nul
"%NSSM%" set %SVC% DisplayName "SMS Gateway - %SVC%" >nul
"%NSSM%" set %SVC% Description "%~3" >nul
"%NSSM%" set %SVC% Start SERVICE_AUTO_START >nul
rem Se o processo terminar (por exemplo, apos demasiados erros seguidos), volta a arrancar passados 5 segundos
"%NSSM%" set %SVC% AppExit Default Restart >nul
"%NSSM%" set %SVC% AppRestartDelay 5000 >nul
"%NSSM%" set %SVC% AppStdout "%ROOT%\logs\%SVC%.out.log" >nul
"%NSSM%" set %SVC% AppStderr "%ROOT%\logs\%SVC%.err.log" >nul
"%NSSM%" set %SVC% AppRotateFiles 1 >nul
"%NSSM%" set %SVC% AppRotateBytes 5000000 >nul
"%NSSM%" start %SVC% >nul
exit /b 0
