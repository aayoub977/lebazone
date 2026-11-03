@echo off
setlocal EnableDelayedExpansion
title Lebazone - put it online (one-time setup)
set "DEV=%~dp0"
set "CF=%DEV%tools\cloudflared.exe"
set "TUN=%DEV%tunnel"
set "LOG=%DEV%online-setup-log.txt"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
echo Lebazone online setup > "%LOG%"
echo.
echo  Before this: lebazone.store must be added to your free Cloudflare account
echo  and Namecheap must use Cloudflare's two nameservers (see Claude's steps).
echo.
pause

echo ==== 1/5  Cloudflare Tunnel program ====
if not exist "%DEV%tools" mkdir "%DEV%tools"
if not exist "%CF%" (
  curl -L -o "%CF%" https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe || goto :fail
)
"%CF%" --version || goto :fail

echo ==== 2/5  Sign in to Cloudflare (a browser opens - choose lebazone.store and Authorize) ====
if not exist "%USERPROFILE%\.cloudflared\cert.pem" (
  "%CF%" tunnel login || goto :fail
)

echo ==== 3/5  Create the tunnel and the web addresses ====
"%CF%" tunnel info lebazone >nul 2>&1 || ( "%CF%" tunnel create lebazone >> "%LOG%" 2>&1 || goto :fail )
set "CRED="
set "TID="
rem The tunnel's key file is named after its ID: %USERPROFILE%\.cloudflared\ID.json
for %%F in ("%USERPROFILE%\.cloudflared\*.json") do ( set "CRED=%%~fF" & set "TID=%%~nF" )
if not defined TID ( echo  No tunnel key file found in %USERPROFILE%\.cloudflared & goto :fail )
if not exist "!CRED!" ( echo  Tunnel key file !CRED! not found. & goto :fail )
for %%H in (lebazone.store www.lebazone.store admin.lebazone.store) do (
  "%CF%" tunnel route dns --overwrite-dns lebazone %%H >> "%LOG%" 2>&1 || goto :fail
  echo   %%H  ok
)
if not exist "%TUN%" mkdir "%TUN%"
(
  echo tunnel: !TID!
  echo credentials-file: !CRED!
  echo ingress:
  echo   - hostname: admin.lebazone.store
  echo     service: http://127.0.0.1:8100
  echo   - hostname: lebazone.store
  echo     service: http://127.0.0.1:3100
  echo   - hostname: www.lebazone.store
  echo     service: http://127.0.0.1:3100
  echo   - service: http_status:404
) > "%TUN%\config.yml"
type "%TUN%\config.yml" >> "%LOG%"

echo ==== 4/5  Switch Lebazone to its online addresses ====
"%PHP%" "%DEV%online_mode.php" on || goto :fail

echo ==== 5/5  Build the website for production (3-5 minutes) ====
cd /d "%DEV%..\website_v.1.3.1\website_v.1.3.1"
call npm run build >> "%LOG%" 2>&1 || goto :fail

echo.
echo  Done. From now on start Lebazone with  start-online.bat
echo OK >> "%LOG%"
pause
exit /b 0
:fail
echo.
echo  Stopped - send Claude the file local-dev\online-setup-log.txt
echo FAILED >> "%LOG%"
pause
exit /b 1
