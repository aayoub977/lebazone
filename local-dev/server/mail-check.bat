@echo off
title Lebazone - email check
set "SRV=%~dp0"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
set /p TO=Send a test code email to (your email address): 
"%PHP%" "%SRV%mail_check.php" %TO%
echo.
echo Send Claude what it says above.
pause
