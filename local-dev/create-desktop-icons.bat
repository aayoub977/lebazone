@echo off
title Lebazone - desktop icons
rem   create-desktop-icons.bat          -> "Lebazone" icon (one app for everyone - opens full screen)
rem   create-desktop-icons.bat station  -> "Lebazone Order Station" (for the touch-screen PC)
rem   create-desktop-icons.bat all      -> both
set "WHICH=%~1"
if "%WHICH%"=="" set "WHICH=admin"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0create-desktop-icons.ps1" -Which %WHICH%
pause
