@echo off
rem Lebazone server: keeps one part running forever (restarts it if it stops).
rem   run-service.bat admin | web | queue | scheduler | tunnel
setlocal
set "SRV=%~dp0"
for %%I in ("%SRV%..\..") do set "ROOT=%%~fI"
set "ADMIN=%ROOT%\admin_panel_v1.3.1"
set "WEB=%ROOT%\website_v.1.3.1\website_v.1.3.1"
set "LD=%ROOT%\local-dev"
set "LOGS=%SRV%logs"
if not exist "%LOGS%" mkdir "%LOGS%"
set "PATH=%PATH%;C:\Program Files\nodejs;C:\Program Files\Git\cmd"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
set "MYSQL="
for /d %%D in (C:\laragon\bin\mysql\*) do if exist "%%D\bin\mysql.exe" set "MYSQL=%%D\bin\mysql.exe"

:waitdb
"%MYSQL%" -u root -h 127.0.0.1 -e "SELECT 1" >nul 2>&1 && goto :loop
timeout /t 5 /nobreak >nul
goto :waitdb

:loop
echo %date% %time% start %1 >> "%LOGS%\services.log"
if /i "%1"=="admin" (
  cd /d "%ADMIN%"
  "%PHP%" artisan serve --host=127.0.0.1 --port=8100 >nul 2>&1
)
if /i "%1"=="queue" (
  cd /d "%ADMIN%"
  "%PHP%" artisan queue:work --tries=3 --max-time=3600 >> "%LOGS%\queue.log" 2>&1
)
if /i "%1"=="scheduler" (
  cd /d "%ADMIN%"
  "%PHP%" artisan schedule:work >> "%LOGS%\scheduler.log" 2>&1
)
if /i "%1"=="web" (
  if exist "%SRV%building.flag" ( timeout /t 5 /nobreak >nul & goto :loop )
  cd /d "%WEB%"
  call npx next start -p 3100 >> "%LOGS%\web.log" 2>&1
)
if /i "%1"=="tunnel" (
  "%LD%\tools\cloudflared.exe" tunnel --loglevel warn --config "%LD%\tunnel\config.yml" run >> "%LOGS%\tunnel.log" 2>&1
)
echo %date% %time% stopped %1 - restarting >> "%LOGS%\services.log"
timeout /t 5 /nobreak >nul
goto :loop
