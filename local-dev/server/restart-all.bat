@echo off
title Lebazone - restart the live shop
echo  Restarting Lebazone (about 20 seconds)...
taskkill /f /im cloudflared.exe >nul 2>&1
taskkill /f /im php.exe >nul 2>&1
powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='node.exe'\" | Where-Object { $_.CommandLine -like '*next*start*3100*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }" >nul 2>&1
echo  The parts restart by themselves within a few seconds.
timeout /t 15 /nobreak >nul
call "%~dp0status.bat"
