@echo off
title Lebazone - rebuild admin + driver panels
cd /d "%~dp0..\admin_panel_v1.3.1"
echo Rebuilding the admin and driver panels (about 1 minute)...
call npm run build || ( echo BUILD FAILED & pause & exit /b 1 )
echo.
echo Done. Refresh the page on your phone / PC.
pause
