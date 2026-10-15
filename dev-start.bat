@echo off
rem ====================================================================
rem  Arranca TUDO o que e preciso para testar a API sem modem:
rem    1. prepara a base de testes (smsphp_test) com dados de exemplo
rem    2. abre 2 janelas: dispatcher e worker de aprovacoes
rem  A API e o Apache do XAMPP (porta 8080): tem de estar ligado no XAMPP Control Panel.
rem  O .env tem de ter DB_NAME=smsphp_test e API_KEYS (ver .env.example).
rem
rem  Uso:   dev-start.bat           (mantem os dados que ja la estao)
rem         dev-start.bat reset     (apaga tudo e volta a por os dados de exemplo)
rem  Parar: dev-stop.bat
rem ====================================================================
setlocal
cd /d "%~dp0"

set "PHP=C:\xampp\php\php.exe"
set "PORT=8080"

rem --- Configuracao so para estas janelas (nao toca no .env) ---
set "DB_NAME=smsphp_test"
set "API_KEYS=postman-test-key"
set "AUTH_ENABLED=true"
set "APP_DEBUG=true"

if not exist "%PHP%" (
    echo [ERRO] PHP nao encontrado em %PHP%
    pause
    exit /b 1
)

if not exist "vendor\autoload.php" (
    echo A instalar dependencias do Composer...
    call composer install --no-interaction || (echo [ERRO] composer install falhou & pause & exit /b 1)
)

echo.
echo === 1/3 Base de dados de testes ===
set "SEED="
if /i "%~1"=="reset" set "SEED=--seed"
"%PHP%" -d variables_order=EGPCS bin\setup-dev.php %SEED%
if errorlevel 1 (
    echo [ERRO] Nao foi possivel preparar a base. O MySQL do XAMPP esta ligado?
    pause
    exit /b 1
)

echo.
echo === 2/3 A parar processos antigos ===
call "%~dp0dev-stop.bat" quiet

echo.
echo === 3/3 A arrancar dispatcher e aprovacoes ===
start "sms-dispatcher" cmd /k ""%PHP%" -d variables_order=EGPCS bin\dispatcher.php"
start "sms-approvals" cmd /k ""%PHP%" -d variables_order=EGPCS bin\approvals.php"

ping -n 4 127.0.0.1 >nul
echo.
curl.exe -s -w "\n/health -> %%{http_code}\n" http://127.0.0.1:%PORT%/health
echo.
echo ----------------------------------------------------------------
echo  API:      http://127.0.0.1:%PORT%
echo  Header:   X-API-Key: %API_KEYS%
echo  Postman:  importar docs\postman_collection.json
echo  Parar:    dev-stop.bat
echo ----------------------------------------------------------------
endlocal
