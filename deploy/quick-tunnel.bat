@echo off
rem ====================================================================
rem  Quick tunnel da Cloudflare: expoe a API na internet TEMPORARIAMENTE,
rem  num endereco aleatorio (https://qualquer-coisa.trycloudflare.com).
rem
rem  - So para TESTES. O endereco muda sempre que corres isto de novo.
rem  - Deixa esta janela aberta; Ctrl+C fecha o tunnel e o endereco morre.
rem  - Enquanto estiver aberto, a API e publica: protege-a com uma chave
rem    de teste e apaga-a do API_KEYS no .env quando acabares.
rem
rem  Uso:  deploy\quick-tunnel.bat
rem        deploy\quick-tunnel.bat 8080     (outra porta, se o Apache usar outra)
rem ====================================================================
setlocal

set "PORT=8080"
if not "%~1"=="" set "PORT=%~1"

rem cloudflared.exe: na pasta deploy\, no PATH ou na pasta de instalacao habitual
set "CF="
if exist "%~dp0cloudflared.exe" set "CF=%~dp0cloudflared.exe"
if not defined CF (
    where cloudflared >nul 2>&1
    if not errorlevel 1 set "CF=cloudflared"
)
if not defined CF if exist "C:\Program Files (x86)\cloudflared\cloudflared.exe" set "CF=C:\Program Files (x86)\cloudflared\cloudflared.exe"
if not defined CF (
    echo [ERRO] cloudflared.exe nao encontrado.
    echo        Poe o cloudflared.exe na pasta deploy\ ou no PATH.
    pause
    exit /b 1
)

rem A API tem de estar a responder antes de a expor
curl.exe -s -m 5 -o nul http://127.0.0.1:%PORT%/health
if errorlevel 1 (
    echo [ERRO] Nada a responder em http://127.0.0.1:%PORT%/health
    echo        Liga o Apache e confirma o virtual host da porta %PORT%.
    pause
    exit /b 1
)

echo.
echo A abrir o tunnel para http://localhost:%PORT% ...
echo O endereco publico aparece abaixo, numa linha com "trycloudflare.com".
echo Deixa esta janela aberta. Ctrl+C para parar.
echo.
"%CF%" tunnel --url http://localhost:%PORT%

endlocal
