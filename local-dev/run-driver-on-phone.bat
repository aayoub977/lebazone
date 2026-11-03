@echo off
setlocal EnableDelayedExpansion
title Lebazone - driver app on your phone
set "PATH=%PATH%;C:\flutter\bin;C:\Program Files\Git\cmd"
set "LOG=%~dp0driver-phone-run-log.txt"
set "ADB=%LOCALAPPDATA%\Android\Sdk\platform-tools\adb.exe"
cd /d "C:\laragon\www\lebazone\apps\driver" || ( echo  App folder not found & pause & exit /b 1 )
echo  Before you start:
echo   1. Lebazone start-all.bat is running on this PC.
echo   2. Your phone is plugged in by USB with USB debugging ON, and you tapped "Allow" on the phone.
echo  The phone needs NO internet - it talks to this PC through the USB cable.
echo.
"%ADB%" start-server >nul 2>&1
:find_phone
set "SERIAL="
for /f "skip=1 tokens=1,2" %%A in ('"%ADB%" devices') do (
  if "%%B"=="device" call :consider %%A
  if "%%B"=="unauthorized" echo  Phone found but not allowed yet - unlock it and tap "Allow USB debugging".
)
if not defined SERIAL (
  echo  No phone found. Check the cable ^(use a data cable^), USB debugging, and tap Allow on the phone.
  echo  Press any key to look again...
  pause >nul
  goto :find_phone
)
echo  Phone found: !SERIAL!
rem The phone's 127.0.0.1:8100 / 3100 now lead to this PC over the cable.
"%ADB%" -s !SERIAL! reverse tcp:8100 tcp:8100 || goto :fail
"%ADB%" -s !SERIAL! reverse tcp:3100 tcp:3100 >nul 2>&1
"%ADB%" -s !SERIAL! reverse tcp:9090 tcp:9090 >nul 2>&1
echo ==== 1/3  Packages ====
call flutter pub get > "%LOG%" 2>&1 || goto :fail
echo ==== 2/3  Settings for a real phone + app icon ====
call dart run tool/apply_config.dart phone >> "%LOG%" 2>&1 || goto :fail
call dart run flutter_launcher_icons >> "%LOG%" 2>&1 || goto :fail
echo ==== 3/3  Installing on your phone (first time takes 5-10 min) ====
echo  Keep the cable plugged in while you test.  r = reload,  q = quit.
echo.
call flutter run -d !SERIAL! --dart-define=USB_TEST=true
rem put the emulator settings back for run-driver-app.bat
call dart run tool/apply_config.dart dev >nul 2>&1
pause
exit /b 0
:fail
type "%LOG%" | more
echo.
echo  Stopped - send Claude local-dev\driver-phone-run-log.txt
pause
exit /b 1

:consider
echo %1| findstr /b "emulator-" >nul && exit /b 0
set "SERIAL=%1"
exit /b 0
