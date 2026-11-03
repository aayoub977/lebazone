@echo off
setlocal EnableDelayedExpansion
title Lebazone - apply updates
cd /d "%~dp0.."
set "ROOT=%CD%"
set "ADMIN=%ROOT%\admin_panel_v1.3.1"
set "LOG=%~dp0update-log.txt"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
echo Lebazone update log > "%LOG%"

echo ==== 1/4  Database updates ====
cd /d "%ADMIN%"
"%PHP%" artisan migrate --force || goto :fail

echo ==== 2/4  Settings (country, branding, languages, features) ====
for %%S in (setup_lebanon.php set_branding.php enable_arabic.php) do (
  if exist "%~dp0%%S" ( "%PHP%" "%~dp0%%S" || goto :fail )
)
for %%S in ("%~dp0setup_*.feature.php") do ( "%PHP%" "%%S" || goto :fail )
"%PHP%" artisan storage:link --force >nul 2>&1
"%PHP%" artisan optimize:clear

echo ==== 3/4  Admin panel packages ====
call npm install || goto :fail

echo ==== 4/4  Rebuilding the admin panel screens ====
call npm run build || goto :fail

echo.
echo  All updates applied. Refresh the admin panel and website.
echo  (If the website name/colours look old, close the server windows and run start-all.bat again.)
echo OK >> "%LOG%"
timeout /t 20
exit /b 0

:fail
echo.
echo  Stopped because of the error above. Tell Claude what it says.
echo FAILED >> "%LOG%"
pause
exit /b 1
