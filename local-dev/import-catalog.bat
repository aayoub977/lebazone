@echo off
title Lebazone - import catalogue
cd /d "%~dp0"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
"%PHP%" import_catalog.php
echo.
echo  Details: local-dev\catalog-import-log.txt
pause
