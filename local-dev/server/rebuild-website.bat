@echo off
title Lebazone - rebuild the website
rem Run after changing the website's .env (e.g. Firebase keys) - those are baked in at build time.
set "SRV=%~dp0"
for %%I in ("%SRV%..\..") do set "ROOT=%%~fI"
set "WEB=%ROOT%\website_v.1.3.1\website_v.1.3.1"
set "PATH=%PATH%;C:\Program Files\nodejs"
echo building> "%SRV%building.flag"
powershell -NoProfile -Command "Get-NetTCPConnection -LocalPort 3100 -State Listen -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }"
cd /d "%WEB%"
echo Building the website (2-5 minutes)...
call npm run build
set "ERR=%ERRORLEVEL%"
del "%SRV%building.flag" >nul 2>&1
if not "%ERR%"=="0" ( echo. & echo  Build failed - send Claude the lines above. & pause & exit /b 1 )
echo.
echo  Done - the website restarts by itself in a few seconds.
pause
