@echo off
setlocal EnableDelayedExpansion
title Lebazone - make the shop faster
net session >nul 2>&1 || ( echo  Right-click this file and choose "Run as administrator". & pause & exit /b 1 )
rem  1. Turns on PHP's code cache (OPcache).
rem  2. Moves the admin/API (port 8100) from "php artisan serve" - which answers ONE request at
rem     a time - to Laragon's Apache, which answers many at once.
set "SRV=%~dp0"
for %%I in ("%SRV%..\..") do set "ROOT=%%~fI"
set "ADMIN=%ROOT%\admin_panel_v1.3.1"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
set "HTTPD="
for /d %%D in (C:\laragon\bin\apache\*) do if exist "%%D\bin\httpd.exe" set "HTTPD=%%D\bin\httpd.exe"
set "CONF=C:\laragon\etc\apache2\sites-enabled\lebazone-admin.conf"

echo ==== 1/4  PHP code cache ====
powershell -NoProfile -ExecutionPolicy Bypass -File "%SRV%fix-php.ps1" -PhpExe "%PHP%" || goto :fail
"%PHP%" -r "exit(function_exists('opcache_get_status') ? 0 : 1);" >nul 2>&1 && echo  OPcache: on || echo  ! OPcache could not be switched on - the shop still works, just slower.

echo ==== 2/4  Apache for the admin / API ====
if not defined HTTPD ( echo  Laragon's Apache was not found ^(C:\laragon\bin\apache^). & goto :fail )
if not exist "C:\laragon\etc\apache2\sites-enabled" ( echo  Laragon's Apache folder C:\laragon\etc\apache2\sites-enabled is missing. & goto :fail )
set "A=%ADMIN:\=/%"
> "%CONF%" (
  echo # Lebazone admin / API for the Cloudflare tunnel - written by local-dev\server\speed-up.bat
  echo Listen 127.0.0.1:8100
  echo ^<VirtualHost 127.0.0.1:8100^>
  echo     DocumentRoot "!A!/public"
  echo     ^<Directory "!A!/public"^>
  echo         Options -Indexes +FollowSymLinks
  echo         AllowOverride All
  echo         Require all granted
  echo     ^</Directory^>
  echo     ErrorLog "!A!/storage/logs/apache-error.log"
  echo ^</VirtualHost^>
)
"%HTTPD%" -t >"%SRV%apache-test.txt" 2>&1
if errorlevel 1 (
  type "%SRV%apache-test.txt"
  del "%CONF%"
  echo  Apache did not accept the settings - nothing was changed.
  goto :fail
)
echo  OK

echo ==== 3/4  Stop the old one-at-a-time server ====
schtasks /end /tn "Lebazone\admin" >nul 2>&1
powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -match 'run-service\.bat.{0,3} admin' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }; Get-NetTCPConnection -LocalPort 8100 -State Listen -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }"
rem the admin task now only waits (run-service.bat sees the Apache file)
schtasks /run /tn "Lebazone\admin" >nul 2>&1
echo  OK

echo ==== 4/4  Restart Apache ====
echo.
echo  Now in Laragon click "Stop", then "Start All".
echo  ^(Make sure Apache is ticked in Laragon - Menu ^> Preferences ^> Services ^& Ports.^)
echo.
pause
set "OK="
for /l %%N in (1,1,10) do if not defined OK (
  curl -s -o nul -w "%%{http_code}" http://127.0.0.1:8100/customer/countries?limit=1 > "%SRV%code.txt" 2>nul
  set /p CODE=<"%SRV%code.txt"
  if "!CODE!"=="200" ( set "OK=1" ) else ( timeout /t 3 /nobreak >nul )
)
del "%SRV%code.txt" >nul 2>&1
if not defined OK (
  echo  The admin is not answering on port 8100 yet ^(last answer: !CODE!^).
  echo  Check Apache is running ^(green^) in Laragon, then run this file again.
  goto :fail
)
echo  Admin / API answering through Apache.
echo.
echo  Done - the shop should load much faster now.
pause
exit /b 0

:fail
echo.
echo  Stopped - tell Claude what it says above.
pause
exit /b 1
