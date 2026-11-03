@echo off
title Lebazone - reload settings
rem Run after changing admin_panel_v1.3.1\.env, or if the admin shows the blue theme / error 500.
set "SRV=%~dp0"
for %%I in ("%SRV%..\..") do set "ROOT=%%~fI"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
cd /d "%ROOT%\admin_panel_v1.3.1"
"%PHP%" artisan config:cache
echo.
echo  Done - refresh the admin and the website.
pause
