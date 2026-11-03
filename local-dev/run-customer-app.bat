@echo off
setlocal EnableDelayedExpansion
title Lebazone - customer app
set "PATH=%PATH%;C:\flutter\bin;C:\Program Files\Git\cmd"
set "LOG=%~dp0app-run-log.txt"
rem Apps live at a short path - the Downloads path is too long for Windows (260-char limit)
cd /d "C:\laragon\www\lebazone\apps\customer" || ( echo  App folder C:\laragon\www\lebazone\apps\customer not found & pause & exit /b 1 )
if not exist android\app\google-services.json (
  echo  google-services.json is missing - finish the Firebase step first ^(ask Claude^).
  pause & exit /b 1
)
echo  Make sure start-all.bat is running ^(the app talks to your PC on port 8100^)
echo  and the virtual phone is open in Android Studio.
echo.
echo ==== 0/3  Virtual phone ====
set "STEP=%~dp0app-run-steps.txt"
echo %TIME% start > "%STEP%"
set "SDK=%LOCALAPPDATA%\Android\Sdk"
set "ADB=%SDK%\platform-tools\adb.exe"
"%ADB%" start-server >nul 2>&1
"%ADB%" devices > "%TEMP%\hader-adb.txt" 2>&1
type "%TEMP%\hader-adb.txt" >> "%STEP%"
findstr /c:"emulator-" "%TEMP%\hader-adb.txt" >nul && goto :phone_ready
set "AVD="
for /f "delims=" %%A in ('call "%SDK%\emulator\emulator.exe" -list-avds 2^>nul') do (
  if not defined AVD set "AVD=%%A"
  echo %%A| findstr /i "pixel" >nul && set "AVD=%%A"
)
echo %TIME% avd=!AVD! >> "%STEP%"
if not defined AVD (
  echo  No virtual phone found - create one in Android Studio ^(More Actions - Virtual Device Manager^).
  pause & exit /b 1
)
echo  Starting virtual phone !AVD! - leave its window open ...
start "Lebazone virtual phone" "%SDK%\emulator\emulator.exe" -avd !AVD! -no-snapshot-load -gpu host
echo  Waiting for it to boot ^(about a minute^) ...
set /a TRIES=0
:wait_boot
set /a TRIES+=1
if !TRIES! gtr 60 goto :phone_ready
timeout /t 3 >nul
"%ADB%" shell getprop sys.boot_completed 2>nul | findstr "1" >nul || goto :wait_boot
:phone_ready
echo %TIME% phone ready >> "%STEP%"
echo  Virtual phone is ready.
echo ==== 1/3  Packages ====
call flutter pub get > "%LOG%" 2>&1 || goto :fail
echo ==== 2/3  Lebazone settings + app icon ====
call dart run tool/apply_config.dart dev >> "%LOG%" 2>&1 || goto :fail
call dart run flutter_launcher_icons >> "%LOG%" 2>&1 || goto :fail
echo ==== 3/3  Starting the app on the virtual phone (first time takes 5-10 min) ====
echo  While it runs: press  r  to reload after Claude changes something,  q  to quit.
echo.
call flutter run -d emulator
pause
exit /b 0
:fail
type "%LOG%" | more
echo.
echo  Stopped - tell Claude what it says ^(full log: local-dev\app-run-log.txt^).
pause
exit /b 1
