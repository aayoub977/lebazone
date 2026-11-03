@echo off
setlocal EnableDelayedExpansion
title Lebazone - add Purchasing (Orders Online)
cd /d "%~dp0.."
set "ROOT=%CD%"
set "ADMIN=%ROOT%\admin_panel_v1.3.1"
set "LOG=%~dp0purchasing-log.txt"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
echo Lebazone purchasing log > "%LOG%"
echo.
echo  Make sure Laragon is running (MySQL started) before continuing.
echo.

echo ==== 1/4  Backing up the database ====
set "MYSQLDUMP="
for /d %%D in (C:\laragon\bin\mysql\mysql-*) do if exist "%%D\bin\mysqldump.exe" set "MYSQLDUMP=%%D\bin\mysqldump.exe"
if defined MYSQLDUMP (
  for /f "tokens=2 delims==" %%P in ('findstr /b "DB_PASSWORD=" "%ADMIN%\.env"') do set "DBPASS=%%P"
  if defined DBPASS ( "%MYSQLDUMP%" -u root -p!DBPASS! lebazone > "%~dp0backups\lebazone-before-purchasing.sql" ) else ( "%MYSQLDUMP%" -u root lebazone > "%~dp0backups\lebazone-before-purchasing.sql" )
  echo    saved local-dev\backups\lebazone-before-purchasing.sql
) else (
  echo    mysqldump not found - skipping the backup
)

echo ==== 2/4  Database update (Purchasing tables + permission) ====
cd /d "%ADMIN%"
"%PHP%" artisan migrate --force || goto :fail

echo ==== 3/4  Importing your Orders Online items, suppliers and pictures ====
"%PHP%" "%~dp0setup_purchasing_import.feature.php" >> "%LOG%" 2>&1 || goto :fail
type "%LOG%" | findstr /c:"Purchasing import"
"%PHP%" "%~dp0setup_purchasing_descriptions.feature.php" >> "%LOG%" 2>&1 || goto :fail
type "%LOG%" | findstr /c:"Descriptions:"
"%PHP%" artisan storage:link --force >nul 2>&1
"%PHP%" artisan optimize:clear >nul

echo ==== 4/4  Rebuilding the admin panel screens ====
call npm run build >> "%LOG%" 2>&1 || goto :fail

echo.
echo  Done. In the admin panel: log out and back in, then open Catalog ^> Purchasing.
echo OK >> "%LOG%"
pause
exit /b 0

:fail
echo.
echo  Stopped because of an error. The details are in local-dev\purchasing-log.txt - tell Claude what it says.
echo FAILED >> "%LOG%"
pause
exit /b 1
