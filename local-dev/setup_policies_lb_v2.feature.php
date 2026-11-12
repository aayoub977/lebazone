<?php
// Lebazone: once - removes the phone / WhatsApp number from the customer policies that were
// already written (2026-11-12, asked for: "remove any phone number"). Only that line and the
// "contact us on WhatsApp" wording change; everything else you wrote is kept. Flag lebazone_policies_v2.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::table('settings')->where('variable', 'lebazone_policies_v2')->value('value') === '1') return;

$fields = ['privacy_policy', 'return_policy', 'shipping_policy', 'cancellation_policy', 'terms_conditions'];
$clean = function ($html) {
    $h = (string) $html;
    $h = preg_replace('~<br>\s*WhatsApp:\s*<a[^>]*>[^<]*</a>~i', '', $h);
    $h = preg_replace('~WhatsApp:\s*<a[^>]*wa\.me[^>]*>[^<]*</a>\s*(<br>)?~i', '', $h);
    $h = str_replace(' - or contact us on WhatsApp.', ' - or email us.', $h);
    $h = str_replace(['+961 81 976 696', '+96181976696'], '', $h);
    return $h;
};

$n = 0;
$c = DB::table('countries')->whereRaw('UPPER(code) = ?', ['LB'])->first();
if ($c) {
    $upd = [];
    foreach ($fields as $f) {
        if (!property_exists($c, $f)) continue;
        $new = $clean($c->$f);
        if ($new !== (string) $c->$f) $upd[$f] = $new;
    }
    if ($upd) { DB::table('countries')->where('id', $c->id)->update($upd); $n += count($upd); }
    if (Schema::hasTable('country_translations')) {
        foreach (DB::table('country_translations')->where('country_id', $c->id)->get() as $row) {
            $upd = [];
            foreach ($fields as $f) {
                if (!property_exists($row, $f)) continue;
                $new = $clean($row->$f);
                if ($new !== (string) $row->$f) $upd[$f] = $new;
            }
            if ($upd) { DB::table('country_translations')->where('id', $row->id)->update($upd + ['updated_at' => now()]); $n += count($upd); }
        }
    }
}
DB::table('settings')->updateOrInsert(['variable' => 'lebazone_policies_v2'], ['value' => '1']);
\Illuminate\Support\Facades\Artisan::call('optimize:clear');
try { \Illuminate\Support\Facades\Artisan::call('config:cache'); } catch (\Throwable $e) {}
echo "Policies: phone number removed from $n policy text(s).\n";
