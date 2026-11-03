@echo off
title Lebazone - status
set "SRV=%~dp0"
echo.
echo  ===== Lebazone live shop =====
curl -s -o nul -m 5 http://127.0.0.1:8100/customer/settings && ( echo  Admin + API ......... running ) || ( echo  Admin + API ......... NOT running )
curl -s -o nul -m 10 http://127.0.0.1:3100/ && ( echo  Website ............. running ) || ( echo  Website ............. NOT running ^(or rebuilding^) )
tasklist /fi "imagename eq cloudflared.exe" | find /i "cloudflared" >nul && ( echo  Cloudflare tunnel ... running ) || ( echo  Cloudflare tunnel ... NOT running )
curl -s -o nul -m 10 -w "  lebazone.store ...... HTTP %%{http_code}\n" https://lebazone.store/
echo.
echo  Last update:
if exist "%SRV%logs\update.log" ( powershell -NoProfile -Command "Get-Content '%SRV%logs\update.log' -Tail 6" ) else ( echo   none yet )
echo.
pause
