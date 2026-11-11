<?php
// Lebazone: once - switches the whole shop to the new logo (red shopping bag + "Lebazone",
// asked for on 2026-11-11). Pictures come from local-dev/brand-assets/brand/.
//   admin panel logo + favicon, website logo + favicon, PWA (phone home-screen) icon,
//   invoice logo (only if one was set). New file names (-v2) so browsers and Cloudflare
//   don't keep showing the old cached logo. Flag lebazone_logo_v2.
// Afterwards the logos can still be changed normally in admin -> Settings.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

if (DB::table('settings')->where('variable', 'lebazone_logo_v2')->value('value') === '1') return;

try {
    $src = __DIR__ . '/brand-assets/brand';
    $st = $base . '/storage/app/public';
    $files = [
        'logo'          => ['lebazone-logo.png',        'logo/lebazone-logo-v2.png'],
        'web_logo'      => ['lebazone-logo.png',        'front_end/web_logo/lebazone-logo-v2.png'],
        'admin_favicon' => ['lebazone-favicon-64.png',  'admin_favicon/lebazone-favicon-v2.png'],
        'favicon'       => ['lebazone-favicon-64.png',  'front_end/favicon/lebazone-favicon-v2.png'],
        'pwa_icon'      => ['lebazone-pwa-512.png',     'front_end/pwa_icon/lebazone-pwa-512-v2.png'],
    ];
    foreach ($files as $key => [$from, $rel]) {
        @mkdir(dirname("$st/$rel"), 0777, true);
        if (!copy("$src/$from", "$st/$rel")) throw new RuntimeException("could not copy $from");
        DB::table('settings')->updateOrInsert(['variable' => $key], ['value' => $rel]);
    }
    // invoice: only replace a logo that was set on purpose (empty = uses the main logo)
    $inv = (string) DB::table('settings')->where('variable', 'invoice_logo')->value('value');
    if (trim($inv) !== '') {
        $rel = 'invoice/lebazone-logo-v2.png';
        @mkdir(dirname("$st/$rel"), 0777, true);
        copy("$src/lebazone-logo.png", "$st/$rel");
        DB::table('settings')->where('variable', 'invoice_logo')->update(['value' => $rel]);
    }
    // the admin panel's built-in pictures (login page etc.)
    foreach (['logo.png' => 'lebazone-logo.png', 'admin_logo.png' => 'lebazone-logo.png', 'favicon.png' => 'lebazone-favicon-64.png'] as $to => $from) {
        @copy("$src/$from", "$base/public/images/$to");
    }

    DB::table('settings')->updateOrInsert(['variable' => 'lebazone_logo_v2'], ['value' => '1']);
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    try { \Illuminate\Support\Facades\Artisan::call('config:cache'); } catch (\Throwable $e) {}
    echo "New Lebazone logo applied (admin, website, favicon, PWA icon" . (trim($inv) !== '' ? ', invoice' : '') . ").\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "  ! New logo failed: " . $e->getMessage() . "\n");
    exit(1);
}
