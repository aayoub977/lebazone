@echo off
title Hader - enable Arabic
cd /d "%~dp0"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
"%PHP%" enable_arabic.php > arabic-log.txt 2>&1
cd /d "%~dp0..\admin_panel_v1.3.1"
"%PHP%" artisan optimize:clear >> "%~dp0arabic-log.txt" 2>&1
type "%~dp0arabic-log.txt"
timeout /t 10
