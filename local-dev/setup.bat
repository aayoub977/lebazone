@echo off
setlocal EnableDelayedExpansion
title SnapBuy - one-time setup
cd /d "%~dp0.."
set "ROOT=%CD%"
set "ADMIN=%ROOT%\admin_panel_v1.3.1"
set "WEB=%ROOT%\website_v.1.3.1\website_v.1.3.1"
set "LOG=%~dp0setup-log.txt"
echo SnapBuy setup log > "%LOG%"

echo ==== 1/6  Finding PHP and MySQL in Laragon ====
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
if not defined PHP ( echo [X] PHP 8 not found in C:\laragon\bin\php & goto :fail )
set "MYSQL="
for /d %%D in (C:\laragon\bin\mysql\*) do if exist "%%D\bin\mysql.exe" set "MYSQL=%%D\bin\mysql.exe"
if not defined MYSQL ( echo [X] MySQL not found in C:\laragon\bin\mysql & goto :fail )
echo PHP:   %PHP%
echo MySQL: %MYSQL%
"%PHP%" -v
"%PHP%" -r "exit(version_compare(PHP_VERSION,'8.3.0','>=')?0:1);" || ( echo [X] SnapBuy needs PHP 8.3 or newer. In Laragon: Menu ^> PHP ^> Version. & goto :fail )
for %%E in (bcmath ctype curl dom fileinfo gd iconv mbstring openssl pdo_mysql sodium tokenizer xml zip) do (
  "%PHP%" -r "exit(extension_loaded('%%E')?0:1);" || ( echo [X] PHP extension missing: %%E  - enable it in Laragon: Menu ^> PHP ^> Extensions & set MISSING=1 )
)
if defined MISSING goto :fail

echo ==== 2/6  Checking MySQL is running ====
"%MYSQL%" -u root -h 127.0.0.1 -e "SELECT 1" >nul 2>&1 || ( echo [X] MySQL is not running. Open Laragon and click "Start All", then run this again. & goto :fail )
"%MYSQL%" -u root -h 127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS snapbuy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" || goto :fail
echo Database "snapbuy" ready.

echo ==== 3/6  Installing database tables and default data (takes a minute) ====
cd /d "%ADMIN%"
"%PHP%" artisan config:clear || goto :fail
"%PHP%" -d memory_limit=-1 artisan migrate:fresh --force || goto :fail
"%PHP%" -d memory_limit=-1 artisan db:seed --force || goto :fail
"%PHP%" artisan migrate --path=vendor/laravel/passport/database/migrations --force
"%PHP%" artisan passport:install --no-interaction
"%PHP%" artisan storage:link --force

echo ==== 4/6  Creating your local admin login ====
"%PHP%" "%~dp0create_admin.php" admin@snapbuy.local || goto :fail

echo ==== 5/6  Installing website packages (npm, a few minutes) ====
cd /d "%WEB%"
call npm install || goto :fail

echo ==== 6/6  Done ====
echo.
echo  Setup finished. Your local logins are in local-dev\LOCAL_LOGINS.txt
echo  Next: double-click local-dev\start-all.bat
echo.
echo OK >> "%LOG%"
pause
exit /b 0

:fail
echo.
echo  Setup stopped because of the error above. Tell Claude what it says.
echo FAILED >> "%LOG%"
pause
exit /b 1
