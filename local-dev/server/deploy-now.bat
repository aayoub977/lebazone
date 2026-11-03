@echo off
title Lebazone - apply the latest update now
rem Downloads the latest published version and rebuilds the admin + website, showing each step.
call "%~dp0auto-update.bat" show
echo.
echo  If a line says ERROR, WARNING or FAILED, send Claude what it says above.
pause
