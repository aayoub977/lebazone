# Lebazone: turns on the PHP extensions the admin panel needs (zip, gd, intl, ...) in Laragon's php.ini.
param([string]$PhpExe)
$ini = Join-Path (Split-Path $PhpExe) 'php.ini'
if (-not (Test-Path $ini)) {
  $dev = Join-Path (Split-Path $PhpExe) 'php.ini-development'
  if (Test-Path $dev) { Copy-Item $dev $ini } else { Write-Host " php.ini not found next to $PhpExe"; exit 1 }
}
if (-not (Test-Path "$ini.lebazone-backup")) { Copy-Item $ini "$ini.lebazone-backup" }
$extDir = Join-Path (Split-Path $PhpExe) 'ext'
$want = 'curl','fileinfo','gd','intl','mbstring','exif','openssl','pdo_mysql','mysqli','zip','sodium','bcmath','soap','xsl'
$txt = Get-Content $ini -Raw
foreach ($e in $want) {
  if (-not (Test-Path (Join-Path $extDir "php_$e.dll"))) { continue }   # built in, nothing to do
  $on  = "(?m)^\s*extension\s*=\s*`"?(php_)?$e(\.dll)?`"?\s*$"
  $off = "(?m)^\s*;\s*extension\s*=\s*`"?(php_)?$e(\.dll)?`"?\s*$"
  if ($txt -match $on) { continue }
  if ($txt -match $off) { $txt = [regex]::Replace($txt, $off, "extension=$e", 1) }
  else { $txt = $txt.TrimEnd() + "`r`nextension=$e`r`n" }
  Write-Host "  enabled $e"
}
if ($txt -notmatch '(?m)^\s*extension_dir\s*=') { $txt = $txt.TrimEnd() + "`r`nextension_dir=`"$extDir`"`r`n" }
Set-Content -Path $ini -Value $txt -NoNewline -Encoding ASCII
Write-Host " php.ini updated: $ini"
