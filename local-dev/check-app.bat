@echo off
title Lebazone - check app code
set "PATH=%PATH%;C:\flutter\bin;C:\Program Files\Git\cmd"
set "LOG=%~dp0app-check-log.txt"
cd /d "C:\laragon\www\lebazone\apps\customer"
echo  Checking the customer app code for mistakes (about a minute)...
call flutter analyze --no-fatal-infos --no-fatal-warnings > "%LOG%" 2>&1
findstr /r /c:"^ *error " "%LOG%" >nul && ( echo. & echo  Found errors - tell Claude "check the log". ) || ( echo. & echo  No errors. )
findstr /r /c:"^ *error " "%LOG%"
pause
