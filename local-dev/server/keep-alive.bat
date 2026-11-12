@echo off
rem Lebazone server: starts the database (MySQL) and the admin/API web server (Apache)
rem straight from Laragon's folders if they are not running - so the shop comes back
rem by itself after a restart even when the Laragon window does not open/start.
rem Called every minute by run-service.bat (admin). Safe to run any time.
setlocal
set "SRV=%~dp0"
set "LOGS=%SRV%logs"
if not exist "%LOGS%" mkdir "%LOGS%"

rem ---- MySQL (port 3306) ----
netstat -ano | findstr /r /c:":3306 .*LISTENING" >nul
if errorlevel 1 (
  set "MYSQLD=" & set "MYINI="
  for /d %%D in (C:\laragon\bin\mysql\*) do if exist "%%D\bin\mysqld.exe" ( set "MYSQLD=%%D\bin\mysqld.exe" & if exist "%%D\my.ini" set "MYINI=%%D\my.ini" )
  call :startmysql
)

rem ---- Apache (admin + API, port 8100) ----
if exist "C:\laragon\etc\apache2\sites-enabled\lebazone-admin.conf" (
  netstat -ano | findstr /r /c:":8100 .*LISTENING" >nul
  if errorlevel 1 (
    set "HTTPD="
    for /d %%D in (C:\laragon\bin\apache\*) do if exist "%%D\bin\httpd.exe" set "HTTPD=%%D\bin\httpd.exe"
    call :startapache
  )
)
exit /b 0

:startmysql
if not defined MYSQLD ( echo %date% %time% keep-alive: MySQL not found in C:\laragon\bin\mysql >> "%LOGS%\services.log" & exit /b 0 )
echo %date% %time% keep-alive: MySQL was not running - starting it >> "%LOGS%\services.log"
if defined MYINI (
  start "" /B "%MYSQLD%" --defaults-file="%MYINI%"
) else (
  start "" /B "%MYSQLD%"
)
timeout /t 8 /nobreak >nul
exit /b 0

:startapache
if not defined HTTPD ( echo %date% %time% keep-alive: Apache not found in C:\laragon\bin\apache >> "%LOGS%\services.log" & exit /b 0 )
echo %date% %time% keep-alive: Apache was not running - starting it >> "%LOGS%\services.log"
for %%P in ("%HTTPD%\..\..") do set "AROOT=%%~fP"
pushd "%AROOT%"
start "" /B "%HTTPD%" -d "%AROOT%" -f "%AROOT%\conf\httpd.conf"
popd
exit /b 0
