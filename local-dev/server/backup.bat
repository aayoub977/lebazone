@echo off
rem Lebazone server: daily backup of the database (+ photos every Sunday). Keeps 14 days.
rem   1. Saved on this PC in C:\lebazone-backups
rem   2. Copied to Google Drive (folder "lebazone-backups" in My Drive) when Google Drive
rem      for desktop is installed and signed in - so a copy survives if this PC dies.
rem   3. Also OneDrive\lebazone-backups if OneDrive is set up on this PC.
setlocal EnableDelayedExpansion
set "SRV=%~dp0"
for %%I in ("%SRV%..\..") do set "ROOT=%%~fI"
set "ADMIN=%ROOT%\admin_panel_v1.3.1"
set "LOGS=%SRV%logs"
if not exist "%LOGS%" mkdir "%LOGS%"
set "DEST=C:\lebazone-backups"
if not exist "%DEST%" mkdir "%DEST%"

rem ---- Google Drive for desktop: finds "<letter>:\My Drive" (any drive letter) ----
set "GDRIVE="
for %%L in (G H I J K L M N O P Q R S T U V W X Y Z D E F) do (
  if not defined GDRIVE if exist "%%L:\My Drive\" set "GDRIVE=%%L:\My Drive\lebazone-backups"
)
if defined GDRIVE if not exist "!GDRIVE!" mkdir "!GDRIVE!"
set "ONED="
if exist "%USERPROFILE%\OneDrive\" set "ONED=%USERPROFILE%\OneDrive\lebazone-backups"
if defined ONED if not exist "!ONED!" mkdir "!ONED!"

set "MYSQLDUMP="
for /d %%D in (C:\laragon\bin\mysql\*) do if exist "%%D\bin\mysqldump.exe" set "MYSQLDUMP=%%D\bin\mysqldump.exe"
set "DBN=lebazone"
for /f "tokens=1,* delims==" %%A in ('findstr /b "DB_DATABASE=" "%ADMIN%\.env"') do set "DBN=%%B"
for /f %%A in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "D=%%A"

rem ---- database ----
"%MYSQLDUMP%" -u root -h 127.0.0.1 --single-transaction --routines --triggers --default-character-set=utf8mb4 "!DBN!" > "%DEST%\lebazone-db-%D%.sql"
set "SZ=0"
for %%F in ("%DEST%\lebazone-db-%D%.sql") do set "SZ=%%~zF"
if !SZ! LSS 20000 (
  echo %date% %time% BACKUP FAILED - database dump is empty or too small ^(!SZ! bytes^) >> "%LOGS%\backup.log"
  exit /b 1
)
powershell -NoProfile -Command "Compress-Archive -Force -Path '%DEST%\lebazone-db-%D%.sql' -DestinationPath '%DEST%\lebazone-db-%D%.zip'; Remove-Item '%DEST%\lebazone-db-%D%.sql'"

rem ---- photos, every Sunday ----
for /f %%A in ('powershell -NoProfile -Command "(Get-Date).DayOfWeek"') do set "DOW=%%A"
if /i "%DOW%"=="Sunday" powershell -NoProfile -Command "Compress-Archive -Force -Path '%ADMIN%\storage\app\public' -DestinationPath '%DEST%\lebazone-photos-%D%.zip'"

rem ---- copies off this PC ----
set "WHERE=%DEST%"
for %%T in ("!GDRIVE!" "!ONED!") do if not "%%~T"=="" (
  copy /y "%DEST%\lebazone-db-%D%.zip" "%%~T\" >nul && set "WHERE=!WHERE! + %%~T"
  if exist "%DEST%\lebazone-photos-%D%.zip" copy /y "%DEST%\lebazone-photos-%D%.zip" "%%~T\" >nul
)

rem ---- keep 14 days everywhere ----
for %%T in ("%DEST%" "!GDRIVE!" "!ONED!") do if not "%%~T"=="" powershell -NoProfile -Command "Get-ChildItem '%%~T' -Filter 'lebazone-*' -ErrorAction SilentlyContinue | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-14) } | Remove-Item -Force"

if not defined GDRIVE echo %date% %time% note: Google Drive for desktop not found - backup kept on this PC only >> "%LOGS%\backup.log"
echo %date% %time% backup done ^(!SZ! bytes^) to !WHERE! >> "%LOGS%\backup.log"
