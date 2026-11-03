@echo off
rem Lebazone server: every 5 minutes - if new changes were published, apply them.
rem   auto-update.bat          (scheduled, quiet, writes logs\update.log)
rem   auto-update.bat show     (deploy-now.bat: same steps, shown on screen too)
rem Runs from a copy in %TEMP%, because "git reset" below replaces this very file.
setlocal EnableDelayedExpansion
if /i not "%~1"=="--copy" (
  copy /y "%~f0" "%TEMP%\lbz-auto-update.bat" >nul
  "%TEMP%\lbz-auto-update.bat" --copy "%~dp0" %1
  exit /b
)
set "SRV=%~2"
set "MODE=%~3"
for %%I in ("%SRV%..\..") do set "ROOT=%%~fI"
set "ADMIN=%ROOT%\admin_panel_v1.3.1"
set "WEB=%ROOT%\website_v.1.3.1\website_v.1.3.1"
set "LD=%ROOT%\local-dev"
set "LOGS=%SRV%logs"
if not exist "%LOGS%" mkdir "%LOGS%"
set "LOG=%LOGS%\update.log"
set "PATH=%PATH%;C:\Program Files\nodejs;C:\Program Files\Git\cmd;C:\laragon\bin\composer"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
for %%P in ("%PHP%") do set "PATH=%%~dpP;%PATH%"
set "DONE=%SRV%deployed.txt"

rem one update at a time (a lock older than 1 hour is from a crash - ignore it)
if exist "%SRV%updating.lock" (
  powershell -NoProfile -Command "if (((Get-Date) - (Get-Item '%SRV%updating.lock').LastWriteTime).TotalMinutes -lt 60) { exit 1 }" || ( if /i "%MODE%"=="show" echo  Another update is running - wait a few minutes. & exit /b 0 )
)
cd /d "%ROOT%"
git fetch -q origin main >nul 2>&1 || ( if /i "%MODE%"=="show" echo  Could not reach GitHub. & exit /b 1 )
for /f %%A in ('git rev-parse origin/main') do set "NEW=%%A"
rem the last version that was FULLY applied (built) - not just downloaded
set "OLD="
if exist "%DONE%" set /p OLD=<"%DONE%"
if not defined OLD for /f %%A in ('git rev-parse HEAD') do set "OLD=%%A"
if /i not "%MODE%"=="show" if "%OLD%"=="%NEW%" exit /b 0

echo %date% %time% > "%SRV%updating.lock"
call :log ""
call :log "===== %date% %time%  update !OLD:~0,7! to !NEW:~0,7! ====="
git diff --name-only %OLD% %NEW% > "%SRV%changed.txt" 2>nul || ( echo admin_panel_v1.3.1/resources/>"%SRV%changed.txt" & echo website_v.1.3.1/>>"%SRV%changed.txt" )
if /i "%MODE%"=="show" ( echo admin_panel_v1.3.1/resources/>>"%SRV%changed.txt" & echo website_v.1.3.1/>>"%SRV%changed.txt" )
git reset -q --hard origin/main >> "%LOG%" 2>&1 || ( call :log "ERROR: git reset failed" & goto :fail )

set "ADMIN_JS=" & set "ADMIN_PKG=" & set "COMPOSER=" & set "WEB_CHANGED=" & set "WEB_PKG=" & set "WARN="
findstr /b /c:"admin_panel_v1.3.1/composer." "%SRV%changed.txt" >nul && set "COMPOSER=1"
findstr /b /c:"admin_panel_v1.3.1/resources/" /c:"admin_panel_v1.3.1/vite.config" /c:"admin_panel_v1.3.1/package" "%SRV%changed.txt" >nul && set "ADMIN_JS=1"
findstr /b /c:"admin_panel_v1.3.1/package" "%SRV%changed.txt" >nul && set "ADMIN_PKG=1"
findstr /b /c:"website_v.1.3.1/" "%SRV%changed.txt" >nul && set "WEB_CHANGED=1"
findstr /b /c:"website_v.1.3.1/website_v.1.3.1/package" "%SRV%changed.txt" >nul && set "WEB_PKG=1"

cd /d "%ADMIN%"
if defined COMPOSER ( call :log "- composer install" & call composer install --no-interaction >> "%LOG%" 2>&1 || ( call :log "ERROR: composer install failed" & goto :fail ) )
call :log "- database"
"%PHP%" artisan migrate --force >> "%LOG%" 2>&1 || ( call :log "ERROR: database update (migrate) failed" & goto :fail )
rem setup scripts: a failing one is reported but does NOT stop the screens from being rebuilt
for %%S in (setup_lebanon.php set_branding.php enable_arabic.php) do (
  if exist "%LD%\%%S" ( "%PHP%" "%LD%\%%S" >> "%LOG%" 2>&1 || ( call :log "WARNING: %%S failed" & set "WARN=1" ) )
)
for %%S in ("%LD%\setup_*.feature.php") do ( "%PHP%" "%%S" >> "%LOG%" 2>&1 || ( call :log "WARNING: %%~nxS failed" & set "WARN=1" ) )
"%PHP%" artisan optimize:clear >> "%LOG%" 2>&1
"%PHP%" artisan queue:restart >> "%LOG%" 2>&1
if defined ADMIN_JS (
  call :log "- admin screens rebuild (2-4 min)"
  if defined ADMIN_PKG ( call npm install --no-audit --no-fund >> "%LOG%" 2>&1 || ( call :log "ERROR: admin npm install failed" & goto :fail ) )
  call npm run build >> "%LOG%" 2>&1 || ( call :log "ERROR: admin build failed" & goto :fail )
)
if defined WEB_CHANGED (
  call :log "- website rebuild (2-5 min)"
  cd /d "%WEB%"
  if defined WEB_PKG ( call npm install --no-audit --no-fund >> "%LOG%" 2>&1 || ( call :log "ERROR: website npm install failed" & goto :fail ) )
  echo building > "%SRV%building.flag"
  powershell -NoProfile -Command "Get-NetTCPConnection -LocalPort 3100 -State Listen -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }" >nul 2>&1
  call npm run build >> "%LOG%" 2>&1
  set "BUILD_ERR=!errorlevel!"
  del "%SRV%building.flag" >nul 2>&1
  if not "!BUILD_ERR!"=="0" ( call :log "ERROR: website build failed" & goto :fail )
)
> "%DONE%" echo %NEW%
if defined WARN ( call :log "OK with warnings (see WARNING lines) %date% %time%" ) else ( call :log "OK %date% %time%" )
del "%SRV%updating.lock" >nul 2>&1
exit /b 0

:fail
call :log "FAILED %date% %time% - will try again in 5 minutes"
del "%SRV%building.flag" >nul 2>&1
del "%SRV%updating.lock" >nul 2>&1
if /i "%MODE%"=="show" ( echo. & echo  Last lines of the log: & powershell -NoProfile -Command "Get-Content '%LOG%' -Tail 30" )
exit /b 1

:log
>> "%LOG%" echo(%~1
if /i "%MODE%"=="show" echo(%~1
exit /b 0
