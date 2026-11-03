@echo off
setlocal EnableDelayedExpansion
title Lebazone - publish changes to the live shop
cd /d "%~dp0.."
set "PATH=%PATH%;C:\Program Files\Git\cmd"
where git >nul 2>&1 || ( echo  Git is not installed on this PC. Install it from https://git-scm.com then run this again. & pause & exit /b 1 )

if not exist ".gitignore" copy /y "%~dp0server\gitignore.txt" ".gitignore" >nul

if not exist ".git" (
  echo  ==== First time: connecting this folder to your private GitHub repository ====
  echo  1. On github.com click New repository, name it lebazone, choose PRIVATE, do NOT add a README.
  echo  2. Copy its address, e.g. https://github.com/yourname/lebazone.git
  echo.
  set /p "REPO=  Paste the repository address here and press Enter: "
  if "!REPO!"=="" ( echo  No address given. & pause & exit /b 1 )
  git init -b main >nul || goto :fail
  git remote add origin "!REPO!" || goto :fail
)

for /f "delims=" %%A in ('git config user.name 2^>nul') do set "GN=%%A"
if not defined GN (
  set /p "GN=  Your name for the change history (e.g. Ahmad): "
  git config user.name "!GN!"
)
for /f "delims=" %%A in ('git config user.email 2^>nul') do set "GE=%%A"
if not defined GE (
  set /p "GE=  Your GitHub email: "
  git config user.email "!GE!"
)

rem Safety: refuse to publish if a secret file slipped in.
git add -A || goto :fail
git diff --cached --name-only | findstr /i /r "\.env$ google-services\.json LOCAL_LOGINS \.pem$ \.key$ \.jks$" >nul && (
  echo.
  echo  STOPPED - a password / key file was about to be uploaded:
  git diff --cached --name-only | findstr /i /r "\.env$ google-services\.json LOCAL_LOGINS \.pem$ \.key$ \.jks$"
  echo  Tell Claude. Nothing was uploaded.
  git reset -q
  pause & exit /b 1
)

git diff --cached --quiet && ( echo  Nothing new to publish - the live shop already has everything. & goto :push )
git commit -q -m "Update %date% %time%" || goto :fail
echo  Changes saved.

:push
echo  Uploading to GitHub ^(the first time it may open a GitHub sign-in window^)...
git push -u origin main || goto :fail
echo.
echo  Published. The mini PC picks it up within 5 minutes and updates the live shop by itself.
echo  ^(Website changes take 2-4 minutes to rebuild there; the shop may be unavailable during that time.^)
pause
exit /b 0

:fail
echo.
echo  Stopped because of the error above - tell Claude what it says.
pause
exit /b 1
