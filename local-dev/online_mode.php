<?php
// Lebazone: switch between local testing and online (lebazone.store via Cloudflare Tunnel).
//   php online_mode.php on    -> https://lebazone.store + https://admin.lebazone.store
//   php online_mode.php off   -> back to http://127.0.0.1:8100 + http://localhost:3100
$on = ($argv[1] ?? 'on') !== 'off';
$root = dirname(__DIR__);
$admin = "$root/admin_panel_v1.3.1";
$web = "$root/website_v.1.3.1/website_v.1.3.1";
$domain = 'lebazone.store';
$api = $on ? "https://admin.$domain" : 'http://127.0.0.1:8100';
$site = $on ? "https://$domain" : 'http://localhost:3100';

function setEnv(string $file, array $vals): void {
    $s = file_get_contents($file);
    foreach ($vals as $k => $v) {
        $line = "$k=$v";
        $re = '/^' . preg_quote($k, '/') . '\s*=.*$/m';
        $s = preg_match($re, $s) ? preg_replace($re, str_replace('$', '\$', $line), $s) : rtrim($s) . "\n$line\n";
    }
    file_put_contents($file, $s);
}

setEnv("$admin/.env", [
    'APP_URL' => $api,
    'APP_DEBUG' => $on ? 'false' : 'true',
    'TRUSTED_PROXIES' => $on ? '*' : '',
]);
setEnv("$web/.env", [
    'NEXT_PUBLIC_API_URL' => $api,
    'NEXT_PUBLIC_BASE_URL' => $site,
    'NEXT_PUBLIC_WEB_NAME' => 'Lebazone',
    'NEXT_PUBLIC_META_TITLE' => '"Lebazone | Gadgets & electronics in Lebanon"',
    'NEXT_PUBLIC_META_DESCRIPTION' => '"Lebazone (ليبازون) - gadgets and electronics delivered across Lebanon. Prices in USD and L.L., pay cash on delivery or with Whish."',
    'NEXT_PUBLIC_META_KEYWORDS' => '"lebazone, ليبازون, electronics lebanon, gadgets lebanon, online shopping lebanon"',
]);

require "$admin/vendor/autoload.php";
$app = require "$admin/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['variable' => 'website_url'], ['value' => $site]);
Illuminate\Support\Facades\Artisan::call('optimize:clear');

echo $on ? "Lebazone is set to ONLINE mode:\n  Website: $site\n  Admin:   $api\n"
         : "Lebazone is back in LOCAL mode:\n  Website: $site\n  Admin:   $api\n";
