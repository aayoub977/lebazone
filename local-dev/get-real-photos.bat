@echo off
setlocal EnableDelayedExpansion
title Lebazone - real product photos
cd /d "%~dp0"
set "PHP="
for /d %%D in (C:\laragon\bin\php\php-8.*) do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
if not exist catalog\images mkdir catalog\images
echo ==== 1/2  Downloading product photos (free Unsplash licence) ====
set /a N=0
for /f "usebackq tokens=1,2" %%A in ("catalog\photos.txt") do (
  if not exist "catalog\images\%%A.jpg" (
    curl -s -L --fail -o "catalog\images\%%A.jpg" "%%B" && ( set /a N+=1 & echo   %%A ) || echo   ! could not download %%A
  )
)
echo   !N! new photos downloaded.
echo ==== 2/2  Putting the photos on the products ====
"%PHP%" apply_photos.php || ( pause & exit /b 1 )
echo.
echo  Done - refresh the website (Ctrl+Shift+R).
pause
