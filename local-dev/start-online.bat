@echo off
setlocal EnableDelayedExpansion
title Lebazone - start ONLINE
cd /d "%~dp0.."
set "ROOT=%CD%"
set "ADMIN=%ROOT%\admin_panel_v1.3.1"
set "WEB=%ROOT%\website_v.1.3.1\website_v.1.3.1"
set "CF=%~dp0tools\cloudflared.exe"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
set "MYSQL="
for /d %%D in (C:\laragon\bin\mysql\*) do if exist "%%D\bin\mysql.exe" set "MYSQL=%%D\bin\mysql.exe"
"%MYSQL%" -u root -h 127.0.0.1 -e "SELECT 1" >nul 2>&1 || (
  echo MySQL is not running. Open Laragon and click "Start All", then run this again.
  pause & exit /b 1
)
if not exist "%~dp0tunnel\config.yml" ( echo Run go-online-setup.bat first. & pause & exit /b 1 )
if not exist "%WEB%\.next\BUILD_ID" ( echo The website is not built yet - run go-online-setup.bat. & pause & exit /b 1 )

start "Lebazone - Admin + API (8100)"  /d "%ADMIN%" cmd /k ""%PHP%" artisan serve --host=127.0.0.1 --port=8100"
start "Lebazone - Background jobs"     /d "%ADMIN%" cmd /k ""%PHP%" artisan queue:work --tries=3"
start "Lebazone - Scheduler"           /d "%ADMIN%" cmd /k ""%PHP%" artisan schedule:work"
start "Lebazone - Website (3100)"      /d "%WEB%"   cmd /k "npx next start -p 3100"
start "Lebazone - Cloudflare Tunnel"   /d "%~dp0"   cmd /k ""%CF%" tunnel --config "%~dp0tunnel\config.yml" run"

echo Waiting for everything to start...
timeout /t 15 /nobreak >nul
start "" "https://lebazone.store"
echo.
echo  Website: https://lebazone.store
echo  Admin:   https://admin.lebazone.store
echo  Lebazone is online while this PC is on and these windows stay open.
echo  Close the 5 windows to take it offline.
timeout /t 10 >nul
