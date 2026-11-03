@echo off
title Lebazone - Cloudflare Tunnel (keep this window open)
cd /d "%~dp0"
if not exist "%~dp0tunnel\config.yml" ( echo Run go-online-setup.bat first. & pause & exit /b 1 )
curl -s -o nul -m 5 http://127.0.0.1:8100/customer/settings || (
  echo  The Lebazone servers are not running - run start-all.bat ^(or start-online.bat^) first.
  pause & exit /b 1
)
echo  Connecting lebazone.store to this PC...
echo  Keep this window open - closing it takes the shop offline.
echo.
"%~dp0tools\cloudflared.exe" tunnel --config "%~dp0tunnel\config.yml" run
echo.
echo  The tunnel stopped. If it said "failed to connect", check the internet and run this again.
pause
