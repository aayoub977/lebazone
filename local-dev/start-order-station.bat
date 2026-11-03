@echo off
title Lebazone - Order Station
rem Copy this file to the Order Station PC (touch screen + printers) and double-click it.
rem   start-order-station.bat          -> one printer (sheets and box labels on the same printer)
rem   start-order-station.bat two      -> two printers: A4 printer for sheets + label printer for boxes
rem   start-order-station.bat setup    -> first time only: choose the printer(s) and log in
set "URL=https://admin.lebazone.store/station"
set "CHROME=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not exist "%CHROME%" set "CHROME=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
if not exist "%CHROME%" set "CHROME=%LocalAppData%\Google\Chrome\Application\chrome.exe"
if not exist "%CHROME%" ( echo Google Chrome is not installed. Install it from google.com/chrome and run this again. & pause & exit /b 1 )
set "P=%LOCALAPPDATA%\LebazoneStation"

if /i "%~1"=="setup" goto :setup
if /i "%~1"=="two" goto :two

rem ---- one printer: full screen, prints silently to this window's printer
start "" "%CHROME%" --user-data-dir="%P%\all" --kiosk --kiosk-printing --noerrdialogs --disable-session-crashed-bubble "%URL%?prints=all"
exit /b 0

:two
start "" "%CHROME%" --user-data-dir="%P%\sheets" --kiosk --kiosk-printing --noerrdialogs --disable-session-crashed-bubble "%URL%?prints=sheets"
timeout /t 3 /nobreak >nul
start "" "%CHROME%" --user-data-dir="%P%\labels" --kiosk-printing --noerrdialogs --window-size=480,360 --window-position=0,0 "%URL%?prints=labels"
exit /b 0

:setup
echo.
echo  FIRST-TIME SETUP
echo  1. In each window that opens, log in with the Order Station account.
echo  2. Press Ctrl+P, choose the printer for that window, set margins to "None", print once.
echo     Chrome remembers that printer for this window from now on.
echo     - "all" or "sheets" window  = A4 printer
echo     - "labels" window           = label printer (only if you use two printers)
echo  3. Close the windows, then start normally (start-order-station.bat  or  start-order-station.bat two).
echo.
start "" "%CHROME%" --user-data-dir="%P%\all" "%URL%?prints=all"
start "" "%CHROME%" --user-data-dir="%P%\sheets" "%URL%?prints=sheets"
start "" "%CHROME%" --user-data-dir="%P%\labels" "%URL%?prints=labels"
pause
