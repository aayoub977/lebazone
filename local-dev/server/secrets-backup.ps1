# Lebazone server: encrypted copy of the shop's secret files on Google Drive.
#   .env files, login keys (oauth), Cloudflare tunnel credentials, Firebase files.
# Packed into a password-protected zip (AES-256, needs 7-Zip) - useless without the password.
# The password is made once and kept ONLY on this PC:  %USERPROFILE%\lebazone-secrets-password.txt
#   -> copy it into your password manager; without it the zip can't be opened.
# A new zip is uploaded only when one of the files changed (keeps the last 3).
# Called by backup.bat:  powershell -File secrets-backup.ps1 "<Google Drive folder>" "<log file>"
param([string]$Drive, [string]$Log)
$ErrorActionPreference = 'Stop'
function Say($m) { Add-Content -Path $Log -Value ("{0} secrets: {1}" -f (Get-Date -Format 'ddd MM/dd/yyyy HH:mm:ss'), $m) }
try {
    if (-not $Drive) { Say 'skipped - Google Drive for desktop not found'; exit 0 }
    $sevenZip = @("$env:ProgramFiles\7-Zip\7z.exe", "${env:ProgramFiles(x86)}\7-Zip\7z.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
    if (-not $sevenZip) { Say 'skipped - 7-Zip is not installed (7-zip.org)'; exit 0 }

    $root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    # each file gets a clear name inside the zip (two of them are called ".env")
    $map = [ordered]@{
        "$root\admin_panel_v1.3.1\.env"                    = 'admin.env'
        "$root\admin_panel_v1.3.1\storage\oauth-private.key" = 'admin-oauth-private.key'
        "$root\admin_panel_v1.3.1\storage\oauth-public.key"  = 'admin-oauth-public.key'
        "$root\website_v.1.3.1\website_v.1.3.1\.env"        = 'website.env'
    }
    Get-ChildItem "$root\local-dev\tunnel" -File -ErrorAction SilentlyContinue | ForEach-Object { $map[$_.FullName] = 'tunnel-' + $_.Name }
    Get-ChildItem "$env:USERPROFILE\.cloudflared" -File -ErrorAction SilentlyContinue | ForEach-Object { $map[$_.FullName] = 'cloudflared-' + $_.Name }
    Get-ChildItem "$root\local-dev\firebase" -File -ErrorAction SilentlyContinue | ForEach-Object { $map[$_.FullName] = 'firebase-' + $_.Name }
    $files = @($map.Keys | Where-Object { Test-Path $_ })
    if (-not $files) { Say 'nothing to back up'; exit 0 }

    # password: made once, kept only on this PC (letters + digits, 32 long)
    $pwFile = "$env:USERPROFILE\lebazone-secrets-password.txt"
    if (-not (Test-Path $pwFile)) {
        $chars = [char[]]'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
        $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
        $bytes = New-Object byte[] 32; $rng.GetBytes($bytes)
        $pw = -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
        Set-Content -Path $pwFile -Value $pw -NoNewline
        Say "NEW password created in $pwFile - copy it into your password manager"
    }
    $pw = (Get-Content $pwFile -Raw).Trim()

    # only when something changed
    $hash = ($files | Sort-Object | ForEach-Object { (Get-FileHash $_ -Algorithm SHA256).Hash + $_ }) -join '|'
    $hash = [BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($hash))).Replace('-', '')
    $stamp = "$env:USERPROFILE\lebazone-secrets-last.txt"
    $dest = Join-Path (Split-Path $Drive -Parent) 'lebazone-keys'
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    $have = Get-ChildItem $dest -Filter 'lbz-keys-*.zip' -ErrorAction SilentlyContinue
    if ((Test-Path $stamp) -and ((Get-Content $stamp -Raw).Trim() -eq $hash) -and $have) { exit 0 }

    $stage = Join-Path $env:TEMP 'lbz-keys-stage'
    if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $stage | Out-Null
    foreach ($f in $files) { Copy-Item $f (Join-Path $stage $map[$f]) -Force }
    $tmp = Join-Path $env:TEMP ("lbz-keys-{0}.zip" -f (Get-Date -Format 'yyyy-MM-dd'))
    if (Test-Path $tmp) { Remove-Item $tmp -Force }
    & $sevenZip a -tzip -mem=AES256 "-p$pw" $tmp (Join-Path $stage '*') -y | Out-Null
    Remove-Item $stage -Recurse -Force
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $tmp)) { Say "FAILED - 7-Zip exit code $LASTEXITCODE"; exit 1 }
    Copy-Item $tmp $dest -Force
    Remove-Item $tmp -Force
    Set-Content -Path $stamp -Value $hash -NoNewline
    Get-ChildItem $dest -Filter 'lbz-keys-*.zip' | Sort-Object LastWriteTime -Descending | Select-Object -Skip 3 | Remove-Item -Force
    Say ("encrypted copy of {0} secret files saved to {1}" -f $files.Count, $dest)
} catch {
    Say ("FAILED - " + $_.Exception.Message)
    exit 1
}
