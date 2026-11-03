# Lebazone: turns on the PHP extensions the admin panel needs (zip, gd, intl, ...) in Laragon's php.ini.
# Only switches on what PHP is NOT already loading, so nothing gets loaded twice.
param([string]$PhpExe)
$dir = Split-Path $PhpExe
$ini = Join-Path $dir 'php.ini'
$bak = "$ini.lebazone-backup"
if (-not (Test-Path $ini)) {
  $dev = Join-Path $dir 'php.ini-development'
  if (Test-Path $dev) { Copy-Item $dev $ini } else { Write-Host " php.ini not found next to $PhpExe"; exit 1 }
}
# start again from the original php.ini (undoes the first version of this script)
if (Test-Path $bak) { Copy-Item $bak $ini -Force } else { Copy-Item $ini $bak }

$extDir = Join-Path $dir 'ext'
$loaded = (& $PhpExe -m 2>$null) | ForEach-Object { $_.Trim().ToLower() }
$txt = Get-Content $ini -Raw

# extension_dir must point at this PHP's ext folder
$re = '(?m)^\s*;?\s*extension_dir\s*=.*$'
$line = "extension_dir=`"$extDir`""
if ($txt -match '(?m)^\s*extension_dir\s*=') { $txt = [regex]::Replace($txt, '(?m)^\s*extension_dir\s*=.*$', $line) }
else { $txt = $txt.TrimEnd() + "`r`n$line`r`n" }

$want = 'curl','fileinfo','gd','intl','mbstring','exif','openssl','pdo_mysql','mysqli','zip','sodium','bcmath'
foreach ($e in $want) {
  if ($loaded -contains $e) { continue }
  if (-not (Test-Path (Join-Path $extDir "php_$e.dll"))) { Write-Host "  ! php_$e.dll is missing from $extDir"; continue }
  $any = "(?m)^[ \t]*;[ \t]*extension[ \t]*=[ \t]*`"?(php_)?$e(\.dll)?`"?\b.*$"
  if ($txt -match $any) { $txt = [regex]::Replace($txt, $any, "extension=$e", 1) }
  else { $txt = $txt.TrimEnd() + "`r`nextension=$e`r`n" }
  Write-Host "  switched on $e"
}
# OPcache: keeps compiled PHP in memory instead of re-reading ~2,000 Laravel files on every request
$opc = "(?m)^[ \t]*;?[ \t]*zend_extension[ \t]*=[ \t]*`"?(php_)?opcache(\.dll)?`"?.*$"
if ($txt -match $opc) { $txt = [regex]::Replace($txt, $opc, 'zend_extension=opcache', 1) }
elseif (Test-Path (Join-Path $extDir 'php_opcache.dll')) { $txt = $txt.TrimEnd() + "`r`nzend_extension=opcache`r`n" }
$opts = [ordered]@{ 'opcache.enable'='1'; 'opcache.enable_cli'='1'; 'opcache.memory_consumption'='256';
  'opcache.interned_strings_buffer'='16'; 'opcache.max_accelerated_files'='30000';
  'opcache.validate_timestamps'='1'; 'opcache.revalidate_freq'='2';
  'realpath_cache_size'='4096K'; 'realpath_cache_ttl'='600' }
foreach ($k in $opts.Keys) {
  $re = "(?m)^[ \t]*;?[ \t]*" + [regex]::Escape($k) + "[ \t]*=.*$"
  if ($txt -match $re) { $txt = [regex]::Replace($txt, $re, "$k=$($opts[$k])", 1) }
  else { $txt = $txt.TrimEnd() + "`r`n$k=$($opts[$k])`r`n" }
}
Set-Content -Path $ini -Value $txt -NoNewline -Encoding ASCII
$now = (& $PhpExe -m 2>&1) | Out-String
if ($now -match 'Warning|Unable') { Write-Host $now }
Write-Host " php.ini updated: $ini"
