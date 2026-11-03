# Custom (Lebazone): desktop app icons. Each opens in its own window (no address bar, no tabs),
# with the Lebazone icon instead of Chrome's.
param([string]$Which = "admin")
$chrome = @("$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
            "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
            "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
$edge = "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
if (-not $chrome) { if (Test-Path $edge) { $chrome = $edge } else { Write-Host "Install Google Chrome first."; exit 1 } }

$iconDir = Join-Path $env:LOCALAPPDATA "Lebazone"
New-Item -ItemType Directory -Force -Path $iconDir | Out-Null
Copy-Item -Force (Join-Path $PSScriptRoot "brand-assets\brand\lebazone*.ico") $iconDir

$desktop = [Environment]::GetFolderPath("Desktop")
$shell = New-Object -ComObject WScript.Shell
function Add-AppIcon($name, $url, $ico, $extra) {
    $lnk = $shell.CreateShortcut((Join-Path $desktop "$name.lnk"))
    $lnk.TargetPath = $chrome
    $lnk.Arguments = "--app=$url $extra"
    $lnk.IconLocation = (Join-Path $iconDir $ico) + ",0"
    $lnk.WorkingDirectory = Split-Path $chrome
    $lnk.Description = $name
    $lnk.Save()
    Write-Host "  + $name"
}
Write-Host "Adding desktop icons:"
# One app for everyone: after login each account lands on its own screen (admin, packer, product entry, driver, station).
if ($Which -in @("admin", "all"))   { Add-AppIcon "Lebazone" "https://admin.lebazone.store/login" "lebazone.ico" "--start-fullscreen" }
if ($Which -in @("station", "all")) { Add-AppIcon "Lebazone Order Station" "https://admin.lebazone.store/station" "lebazone-station.ico" ('--user-data-dir="' + (Join-Path $env:LOCALAPPDATA "LebazoneStation\all") + '" --start-fullscreen --kiosk-printing') }
Write-Host "Done. Double-click the icon on your desktop (it opens full screen - press F11 to leave full screen)."
Write-Host "You can also right-click it > Pin to taskbar."
