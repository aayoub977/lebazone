@echo off
setlocal EnableDelayedExpansion
title Lebazone - rebuild website
rem Rebuild the website after changes. It stops the running website, rebuilds, and starts it again,
rem so browsers never get pages that point to files from the old build (broken layout / huge pictures).
cd /d "%~dp0..\website_v.1.3.1\website_v.1.3.1"

echo ==== 1/3  Stopping the running website (port 3100) ====
for /f "tokens=5" %%P in ('netstat -ano ^| findstr ":3100 " ^| findstr LISTENING') do (
  taskkill /F /PID %%P >nul 2>&1 && echo   stopped process %%P
)
taskkill /F /FI "WINDOWTITLE eq Lebazone - Website (3100)*" >nul 2>&1
timeout /t 2 /nobreak >nul

echo ==== 2/3  Building the website (1-3 minutes) ====
call npm run build || ( echo BUILD FAILED - the old website is NOT running now. Fix the error or run start-online.bat. & pause & exit /b 1 )

echo ==== 3/3  Starting the website again ====
start "Lebazone - Website (3100)" /d "%CD%" cmd /k "npx next start -p 3100"
echo.
echo Website rebuilt and restarted. Visitors get the new version automatically.
timeout /t 8 >nul
