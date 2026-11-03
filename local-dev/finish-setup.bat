@echo off
title Lebazone - finish store setup
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
cd /d "%~dp0..\admin_panel_v1.3.1"
"%PHP%" "%~dp0brand_setup.php" || goto :fail
"%PHP%" artisan optimize:clear >nul
echo.
echo  Done. Refresh the Lebazone website (if it still asks for a location, press Ctrl+Shift+R).
pause
exit /b 0
:fail
echo.
echo  Stopped because of the error above - send Claude a screenshot.
pause
exit /b 1
