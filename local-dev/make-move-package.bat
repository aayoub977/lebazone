@echo off
setlocal EnableDelayedExpansion
title Lebazone - pack the live shop for the mini PC
cd /d "%~dp0.."
set "ROOT=%CD%"
set "ADMIN=%ROOT%\admin_panel_v1.3.1"
set "WEB=%ROOT%\website_v.1.3.1\website_v.1.3.1"
set "PKG=%~dp0move-package"
set "MYSQLDUMP="
for /d %%D in (C:\laragon\bin\mysql\*) do if exist "%%D\bin\mysqldump.exe" set "MYSQLDUMP=%%D\bin\mysqldump.exe"
echo  This packs the shop's DATA (database, photos, settings, tunnel key) into
echo  %PKG%
echo  Copy that folder to the mini PC with a USB stick. It contains passwords - keep it private
echo  and delete it from the USB stick afterwards.
echo.
echo  IMPORTANT: run this right before the move, and close start-online.bat windows first
echo  so no new orders arrive on this PC after the copy.
pause

if exist "%PKG%" rmdir /s /q "%PKG%"
mkdir "%PKG%" || goto :fail

echo ==== 1/4  Database ====
set "DBN=lebazone"
for /f "tokens=1,* delims==" %%A in ('findstr /b "DB_DATABASE=" "%ADMIN%\.env"') do set "DBN=%%B"
"%MYSQLDUMP%" -u root -h 127.0.0.1 --single-transaction --routines --triggers --default-character-set=utf8mb4 "!DBN!" > "%PKG%\lebazone.sql" || goto :fail
echo  database !DBN! saved.

echo ==== 2/4  Photos and uploaded files ====
robocopy "%ADMIN%\storage\app" "%PKG%\storage-app" /E /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 goto :fail

echo ==== 3/4  Settings ====
copy /y "%ADMIN%\.env" "%PKG%\admin.env" >nul || goto :fail
copy /y "%WEB%\.env" "%PKG%\website.env" >nul || goto :fail

echo ==== 4/4  Cloudflare tunnel ====
mkdir "%PKG%\tunnel"
copy /y "%~dp0tunnel\config.yml" "%PKG%\tunnel\config.yml" >nul || goto :fail
set "CRED="
for /f "tokens=1,* delims=:" %%A in ('findstr /c:"credentials-file" "%~dp0tunnel\config.yml"') do set "CRED=%%B"
for /f "tokens=* delims= " %%A in ("!CRED!") do set "CRED=%%A"
if exist "C:!CRED!" set "CRED=C:!CRED!"
if not exist "!CRED!" ( echo  Tunnel key file not found: !CRED! & goto :fail )
copy /y "!CRED!" "%PKG%\tunnel\credentials.json" >nul || goto :fail
copy /y "%~dp0tools\cloudflared.exe" "%PKG%\cloudflared.exe" >nul || goto :fail

echo.
echo  Done. Copy the whole folder
echo     %PKG%
echo  to the mini PC as  C:\laragon\www\lebazone\local-dev\move-package
explorer "%PKG%"
pause
exit /b 0

:fail
echo.
echo  Stopped because of the error above - tell Claude what it says.
pause
exit /b 1
