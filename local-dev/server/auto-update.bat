@echo off
rem Lebazone server: every 5 minutes - if new changes were published, apply them.
setlocal EnableDelayedExpansion
set "SRV=%~dp0"
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

rem one update at a time
if exist "%SRV%updating.lock" (
  forfiles /p "%SRV%" /m updating.lock /d -0 >nul 2>&1 && exit /b 0
)
cd /d "%ROOT%"
git fetch -q origin main >nul 2>&1 || exit /b 0
for /f %%A in ('git rev-parse HEAD') do set "OLD=%%A"
for /f %%A in ('git rev-parse origin/main') do set "NEW=%%A"
if "%OLD%"=="%NEW%" exit /b 0

echo %date% %time% > "%SRV%updating.lock"
echo. >> "%LOG%"
echo ===== %date% %time%  update %OLD:~0,7% -^> %NEW:~0,7% ===== >> "%LOG%"
git diff --name-only %OLD% %NEW% > "%SRV%changed.txt"
git reset -q --hard origin/main >> "%LOG%" 2>&1 || goto :fail

set "ADMIN_JS=" & set "ADMIN_PKG=" & set "COMPOSER=" & set "WEB_CHANGED=" & set "WEB_PKG="
findstr /b /c:"admin_panel_v1.3.1/composer." "%SRV%changed.txt" >nul && set "COMPOSER=1"
findstr /b /c:"admin_panel_v1.3.1/resources/" /c:"admin_panel_v1.3.1/vite.config" /c:"admin_panel_v1.3.1/package" "%SRV%changed.txt" >nul && set "ADMIN_JS=1"
findstr /b /c:"admin_panel_v1.3.1/package" "%SRV%changed.txt" >nul && set "ADMIN_PKG=1"
findstr /b /c:"website_v.1.3.1/" "%SRV%changed.txt" >nul && set "WEB_CHANGED=1"
findstr /b /c:"website_v.1.3.1/website_v.1.3.1/package" "%SRV%changed.txt" >nul && set "WEB_PKG=1"

cd /d "%ADMIN%"
if defined COMPOSER ( echo - composer install >> "%LOG%" & call composer install --no-interaction >> "%LOG%" 2>&1 || goto :fail )
echo - database + settings >> "%LOG%"
"%PHP%" artisan migrate --force >> "%LOG%" 2>&1 || goto :fail
for %%S in (setup_lebanon.php set_branding.php enable_arabic.php) do (
  if exist "%LD%\%%S" ( "%PHP%" "%LD%\%%S" >> "%LOG%" 2>&1 || goto :fail )
)
for %%S in ("%LD%\setup_*.feature.php") do ( "%PHP%" "%%S" >> "%LOG%" 2>&1 || goto :fail )
"%PHP%" artisan optimize:clear >> "%LOG%" 2>&1
"%PHP%" artisan queue:restart >> "%LOG%" 2>&1
if defined ADMIN_JS (
  echo - admin screens rebuild >> "%LOG%"
  if defined ADMIN_PKG ( call npm install --no-audit --no-fund >> "%LOG%" 2>&1 || goto :fail )
  call npm run build >> "%LOG%" 2>&1 || goto :fail
)
if defined WEB_CHANGED (
  echo - website rebuild >> "%LOG%"
  cd /d "%WEB%"
  if defined WEB_PKG ( call npm install --no-audit --no-fund >> "%LOG%" 2>&1 || goto :fail )
  echo building > "%SRV%building.flag"
  powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='node.exe'\" | Where-Object { $_.CommandLine -like '*next*start*3100*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }" >nul 2>&1
  call npm run build >> "%LOG%" 2>&1
  set "BUILD_ERR=!errorlevel!"
  del "%SRV%building.flag" >nul 2>&1
  if not "!BUILD_ERR!"=="0" goto :fail
)
echo OK %date% %time% >> "%LOG%"
del "%SRV%updating.lock" >nul 2>&1
exit /b 0

:fail
echo FAILED %date% %time% - see above >> "%LOG%"
del "%SRV%building.flag" >nul 2>&1
del "%SRV%updating.lock" >nul 2>&1
exit /b 1
