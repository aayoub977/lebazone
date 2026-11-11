<?php
// Brand kit: apply this store's name, logos, colours and texts to the settings.
// Copied to <store>\local-dev\set_branding.php, so apply-updates.bat re-applies it.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;

$b = json_decode(file_get_contents(__DIR__ . '/brand.json'), true);
$K = $b['key']; $N = $b['name']; $NA = $b['name_ar'];
$where = $b['country'] === 'AE' ? ['the UAE', 'الإمارات'] : ['Lebanon', 'لبنان'];
$ml = fn (string $en, ?string $ar = null) => json_encode(array_filter(['en' => $en, 'ar' => $ar]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$year = date('Y');

$values = [
    'app_name'                      => $N,
    'logo'                          => "logo/$K-logo-v2.png",
    'admin_favicon'                 => "admin_favicon/$K-favicon-v2.png",
    'admin_theme_color'             => $b['primary'],
    'copyright_details'             => $ml("© $year $N. All rights reserved.", "© $year $NA. جميع الحقوق محفوظة."),
    'order_prefix'                  => $b['prefix'],
    'customer_light_mode_color'     => $b['primary'],
    'delivery_boy_light_mode_color' => $b['primary'],
    'site_title'                    => $ml($N, $NA),
    'website_url'                   => "http://localhost:{$b['web_port']}",
    'light_mode_color'              => $b['primary'],
    'dark_mode_color'               => $b['primary_dark_mode'],
    'app_title'                     => $ml($N, $NA),
    'app_tagline'                   => $b['tagline'],
    'app_short_description'         => $ml($b['tagline'], $b['tagline_ar']),
    'common_meta_title'             => $ml("$N | Shop online in {$where[0]}", "$NA | تسوّق أونلاين في {$where[1]}"),
    'common_meta_description'       => $ml("$N - {$b['tagline']}", "$NA - {$b['tagline_ar']}"),
    'favicon'                       => "front_end/favicon/$K-favicon-v2.png",
    'web_logo'                      => "front_end/web_logo/$K-logo-v2.png",
    'pwa_name'                      => $N,
    'pwa_description'               => $b['tagline'],
    'pwa_icon'                      => "front_end/pwa_icon/$K-pwa-512-v2.png",
    // Standard delivery only: no same-day/"quick" mode anywhere.
    'quick_commerce_enabled'        => '0',
];

// logo pictures (v2 = the red shopping-bag logo of 2026-11-11; new names so old cached
// copies are never shown) - copied from brand-assets\brand so they always exist
$st = $base . '/storage/app/public';
$pics = ['logo' => "$K-logo.png", 'web_logo' => "$K-logo.png", 'admin_favicon' => "$K-favicon-64.png",
         'favicon' => "$K-favicon-64.png", 'pwa_icon' => "$K-pwa-512.png"];
foreach ($pics as $k => $from) {
    $src = __DIR__ . "/brand-assets/brand/$from";
    if (!is_file($src)) continue;
    @mkdir(dirname("$st/{$values[$k]}"), 0777, true);
    @copy($src, "$st/{$values[$k]}");
}

foreach ($values as $k => $v) {
    $s = Setting::firstOrNew(['variable' => $k]);
    $s->value = $v;
    $s->save();
}
echo "$N branding applied (" . count($values) . " settings, quick delivery off).\n";
