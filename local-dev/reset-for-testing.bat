@echo off
setlocal EnableDelayedExpansion
title Lebazone - start fresh
cd /d "%~dp0.."
echo.
echo  ================================================================
echo   START FRESH - this DELETES:
echo     - all orders, payments, returns, carts, notifications
echo     - all customers
echo     - all products (stock, photos, labels)
echo     - all employees, packers, station login and drivers
echo   It KEEPS: settings, branding, categories, brands, colours, regions,
echo   payment setup, home page layout and the super admin.
echo   A full backup is saved first in local-dev\backups.
echo  ================================================================
echo.
set /p ANSWER=Type RESET and press Enter to continue: 
if /i not "!ANSWER!"=="RESET" ( echo Cancelled - nothing changed. & pause & exit /b 0 )
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
set "DUMP="
for /d %%D in (C:\laragon\bin\mysql\*) do if exist "%%D\bin\mysqldump.exe" set "DUMP=%%D\bin\mysqldump.exe"
if "%DUMP%"=="" ( echo mysqldump not found - is Laragon installed? & pause & exit /b 1 )
"%PHP%" "%~dp0reset_for_testing.php" "%DUMP%"
echo.
pause
