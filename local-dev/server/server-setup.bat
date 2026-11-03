@echo off
setlocal EnableDelayedExpansion
title Lebazone - set up this mini PC as the live server
net session >nul 2>&1 || ( echo  Right-click this file and choose "Run as administrator". & pause & exit /b 1 )
set "SRV=%~dp0"
for %%I in ("%SRV%..\..") do set "ROOT=%%~fI"
set "ADMIN=%ROOT%\admin_panel_v1.3.1"
set "WEB=%ROOT%\website_v.1.3.1\website_v.1.3.1"
set "LD=%ROOT%\local-dev"
set "PKG=%LD%\move-package"
set "LOG=%SRV%setup-log.txt"
set "PATH=%PATH%;C:\Program Files\nodejs;C:\Program Files\Git\cmd;C:\laragon\bin\composer"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
for %%P in ("%PHP%") do set "PATH=%%~dpP;%PATH%"
set "MYSQL="
for /d %%D in (C:\laragon\bin\mysql\*) do if exist "%%D\bin\mysql.exe" set "MYSQL=%%D\bin\mysql.exe"
echo Lebazone server setup > "%LOG%"

echo ==== 0/7  Checking this PC ====
if not defined PHP ( echo  Laragon PHP 8 not found - install Laragon first. & goto :fail )
"%MYSQL%" -u root -h 127.0.0.1 -e "SELECT 1" >nul 2>&1 || ( echo  MySQL is not running - open Laragon and click Start All. & goto :fail )
where node >nul 2>&1 || ( echo  Node.js not found - install Node.js LTS from nodejs.org. & goto :fail )
where composer >nul 2>&1 || ( echo  Composer not found ^(it comes with Laragon: C:\laragon\bin\composer^). & goto :fail )
if not exist "%PKG%\lebazone.sql" ( echo  The move package is missing - copy it to %PKG% first. & goto :fail )
echo  OK

echo ==== 1/7  Settings, photos, tunnel ====
copy /y "%PKG%\admin.env" "%ADMIN%\.env" >nul || goto :fail
copy /y "%PKG%\website.env" "%WEB%\.env" >nul || goto :fail
for %%F in (app\public framework\cache\data framework\sessions framework\views framework\testing logs) do if not exist "%ADMIN%\storage\%%F" mkdir "%ADMIN%\storage\%%F"
robocopy "%PKG%\storage-app" "%ADMIN%\storage\app" /E /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 goto :fail
if not exist "%LD%\tools" mkdir "%LD%\tools"
copy /y "%PKG%\cloudflared.exe" "%LD%\tools\cloudflared.exe" >nul || goto :fail
if not exist "%LD%\tunnel" mkdir "%LD%\tunnel"
copy /y "%PKG%\tunnel\credentials.json" "%LD%\tunnel\credentials.json" >nul || goto :fail
powershell -NoProfile -Command "(Get-Content '%PKG%\tunnel\config.yml') -replace '^credentials-file:.*$', 'credentials-file: %LD%\tunnel\credentials.json' | Set-Content '%LD%\tunnel\config.yml'" || goto :fail
echo  OK

echo ==== 2/7  Database ====
set "DBN=lebazone"
for /f "tokens=1,* delims==" %%A in ('findstr /b "DB_DATABASE=" "%ADMIN%\.env"') do set "DBN=%%B"
set "TABLES=0"
for /f %%A in ('"%MYSQL%" -u root -h 127.0.0.1 -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='!DBN!'"') do set "TABLES=%%A"
if not "!TABLES!"=="0" (
  echo  The database !DBN! already has data on this PC.
  choice /c YN /m "  Replace it with the copy from the move package"
  if errorlevel 2 goto :skipdb
)
"%MYSQL%" -u root -h 127.0.0.1 -e "DROP DATABASE IF EXISTS `!DBN!`; CREATE DATABASE `!DBN!` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || goto :fail
"%MYSQL%" -u root -h 127.0.0.1 --default-character-set=utf8mb4 "!DBN!" < "%PKG%\lebazone.sql" || goto :fail
echo  database !DBN! imported.
:skipdb

echo ==== 3/7  Admin panel packages (a few minutes) ====
cd /d "%ADMIN%"
call composer install --no-interaction >> "%LOG%" 2>&1 || goto :fail
"%PHP%" artisan storage:link --force >> "%LOG%" 2>&1
"%PHP%" artisan migrate --force >> "%LOG%" 2>&1 || goto :fail
"%PHP%" artisan optimize:clear >> "%LOG%" 2>&1
call npm install --no-audit --no-fund >> "%LOG%" 2>&1 || goto :fail
call npm run build >> "%LOG%" 2>&1 || goto :fail
echo  OK

echo ==== 4/7  Website (a few minutes) ====
cd /d "%WEB%"
call npm install --no-audit --no-fund >> "%LOG%" 2>&1 || goto :fail
call npm run build >> "%LOG%" 2>&1 || goto :fail
echo  OK

echo ==== 5/7  Never sleep ====
powercfg /change standby-timeout-ac 0
powercfg /change hibernate-timeout-ac 0
powercfg /hibernate off >nul 2>&1
echo  OK

echo ==== 6/7  Start automatically, update automatically, back up daily ====
set "VBS=%SRV%hidden.vbs"
for %%S in (admin web queue scheduler tunnel) do (
  schtasks /create /tn "Lebazone\%%S" /tr "wscript.exe \"%VBS%\" \"%SRV%run-service.bat\" %%S" /sc onlogon /rl highest /f >nul || goto :fail
)
schtasks /create /tn "Lebazone\auto-update" /tr "wscript.exe \"%VBS%\" \"%SRV%auto-update.bat\"" /sc minute /mo 5 /rl highest /f >nul || goto :fail
schtasks /create /tn "Lebazone\backup" /tr "wscript.exe \"%VBS%\" \"%SRV%backup.bat\"" /sc daily /st 03:30 /rl highest /f >nul || goto :fail
for %%S in (admin web queue scheduler tunnel) do schtasks /run /tn "Lebazone\%%S" >nul
echo  OK

echo ==== 7/7  Checking (30 seconds) ====
timeout /t 30 /nobreak >nul
call "%SRV%status.bat"
echo.
echo  Last 2 steps by hand ^(see the guide^):
echo   - Windows signs in automatically ^(netplwiz^)
echo   - Laragon: Preferences - "Run Laragon when Windows starts" + "Start All automatically"
pause
exit /b 0

:fail
echo.
echo  Stopped - tell Claude what it says above ^(details in local-dev\server\setup-log.txt^).
pause
exit /b 1
