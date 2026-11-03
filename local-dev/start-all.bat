@echo off
setlocal EnableDelayedExpansion
title Lebazone - start all
cd /d "%~dp0.."
set "ROOT=%CD%"
set "ADMIN=%ROOT%\admin_panel_v1.3.1"
set "WEB=%ROOT%\website_v.1.3.1\website_v.1.3.1"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
set "MYSQL="
for /d %%D in (C:\laragon\bin\mysql\*) do if exist "%%D\bin\mysql.exe" set "MYSQL=%%D\bin\mysql.exe"
"%MYSQL%" -u root -h 127.0.0.1 -e "SELECT 1" >nul 2>&1 || (
  echo MySQL is not running. Open Laragon and click "Start All", then run this again.
  pause & exit /b 1
)

start "Lebazone - Admin + API (port 8100)" /d "%ADMIN%" cmd /k ""%PHP%" artisan serve --host=127.0.0.1 --port=8100"
start "Lebazone - Background jobs"          /d "%ADMIN%" cmd /k ""%PHP%" artisan queue:work --tries=3"
start "Lebazone - Scheduler (flash deals, reminders)" /d "%ADMIN%" cmd /k ""%PHP%" artisan schedule:work"
start "Lebazone - Website (port 3100)"    /d "%WEB%"   cmd /k "npm run dev -- -p 3100"

echo Waiting for the servers to start...
timeout /t 12 /nobreak >nul
start "" "http://127.0.0.1:8100"
start "" "http://localhost:3100"
echo.
echo  Admin panel: http://127.0.0.1:8100
echo  Website:     http://localhost:3100
echo  Close the server windows to stop everything.
timeout /t 8 >nul
