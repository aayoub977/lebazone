@echo off
setlocal
title Lebazone - install app tools (Flutter + Android Studio)
set "LOG=%~dp0app-setup-log.txt"
echo Lebazone app setup log > "%LOG%"
echo.
echo  This installs: Git, Android Studio and Flutter (about 3-4 GB, 15-30 minutes).
echo  Windows may ask "Do you want to allow this app to make changes" - click Yes.
echo.
where winget >nul 2>&1 || (
  echo  winget is missing. Install "App Installer" from the Microsoft Store, then run this again.
  echo NO WINGET >> "%LOG%"
  pause & exit /b 1
)

echo ==== 1/4  Git ====
if exist "C:\Program Files\Git\cmd\git.exe" ( echo  already installed ) else (
  winget install -e --id Git.Git --silent --accept-package-agreements --accept-source-agreements
)
set "GIT=C:\Program Files\Git\cmd\git.exe"

echo ==== 2/4  Android Studio ====
if exist "C:\Program Files\Android\Android Studio\bin\studio64.exe" ( echo  already installed ) else (
  winget install -e --id Google.AndroidStudio --silent --accept-package-agreements --accept-source-agreements
)

echo ==== 3/4  Flutter (C:\flutter) ====
if exist "C:\flutter\bin\flutter.bat" ( echo  already installed ) else (
  "%GIT%" clone https://github.com/flutter/flutter.git -b stable --depth 1 C:\flutter || goto :fail
)
powershell -NoProfile -Command "$p=[Environment]::GetEnvironmentVariable('Path','User'); if(-not $p){$p=''}; if($p -notlike '*C:\flutter\bin*'){[Environment]::SetEnvironmentVariable('Path',($p.TrimEnd(';')+';C:\flutter\bin').TrimStart(';'),'User')}"
set "PATH=%PATH%;C:\flutter\bin;C:\Program Files\Git\cmd"
call C:\flutter\bin\flutter.bat config --no-analytics >nul 2>&1
call C:\flutter\bin\flutter.bat --version >> "%LOG%" 2>&1 || goto :fail
call C:\flutter\bin\flutter.bat --version

echo.
echo ==== 4/4  Your turn (about 10 minutes) ====
echo  1. Open "Android Studio" from the Start menu.
echo  2. Choose "Do not import settings", then Next - Standard - Next - accept ALL licences - Finish.
echo     Wait until it finishes downloading the Android SDK.
echo  3. On the Welcome screen: More Actions - Virtual Device Manager - the + button.
echo     Pick "Pixel 8" (or any Pixel) - Next - pick the top recommended system image
echo     (click the download arrow next to it if needed) - Next - Finish.
echo  4. Press the Play button next to the new device to start the virtual phone.
echo.
echo  When the virtual phone shows its home screen, come back here and
pause

echo ==== Accepting Android licences (type y and Enter if asked) ====
call C:\flutter\bin\flutter.bat doctor --android-licenses
call C:\flutter\bin\flutter.bat doctor -v >> "%LOG%" 2>&1
call C:\flutter\bin\flutter.bat doctor
call C:\flutter\bin\flutter.bat devices >> "%LOG%" 2>&1
echo OK >> "%LOG%"
echo.
echo  Done. Tell Claude "app tools installed" - the results are saved in app-setup-log.txt
pause
exit /b 0

:fail
echo.
echo  Stopped because of the error above. Tell Claude what it says.
echo FAILED >> "%LOG%"
pause
exit /b 1
