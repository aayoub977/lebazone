@echo off
title Lebazone - latest sign-up codes
set "F=%~dp0..\admin_panel_v1.3.1\storage\logs\otp-codes.log"
if not exist "%F%" ( echo  No codes sent yet. & pause & exit /b 0 )
echo  Latest sign-up / login codes (test mode):
echo.
powershell -NoProfile -Command "Get-Content -Tail 10 \"%F%\""
echo.
pause
