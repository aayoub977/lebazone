@echo off
title Hader - apply branding
cd /d "%~dp0"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
"%PHP%" set_branding.php > branding-log.txt 2>&1
cd /d "%~dp0..\admin_panel_v1.3.1"
"%PHP%" artisan storage:link --force >> "%~dp0branding-log.txt" 2>&1
"%PHP%" artisan optimize:clear >> "%~dp0branding-log.txt" 2>&1
call npm run build >> "%~dp0branding-log.txt" 2>&1
echo DONE >> "%~dp0branding-log.txt"
type "%~dp0branding-log.txt"
echo.
echo Done. Restart start-all.bat so the website picks up its new name.
timeout /t 15
