@echo off
title SnapBuy - set country to Lebanon
cd /d "%~dp0"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
"%PHP%" setup_lebanon.php > lebanon-log.txt 2>&1
type lebanon-log.txt
cd /d "%~dp0..\admin_panel_v1.3.1"
"%PHP%" artisan cache:clear >> "%~dp0lebanon-log.txt" 2>&1
echo.
echo Done. You can close this window.
timeout /t 6 >nul
