<?php
// Local-dev (Hader): switch Whish on in DEMO mode for Lebanon so the whole
// pay-with-Whish flow can be tested before Whish issues real keys. Leaves it
// alone once you've entered a Whish Channel (sandbox/live).
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Country;

$c = Country::whereRaw('UPPER(code) = ?', ['LB'])->first();
if (!$c) {
    echo "Lebanon not set up yet - skipped Whish demo.\n";
    return;
}
$gw = is_array($c->payment_gateways) ? $c->payment_gateways : (json_decode((string) $c->payment_gateways, true) ?: []);
if (($gw['whish_channel'] ?? '') !== '') {
    echo "Whish keys already entered - demo mode not touched.\n";
    return;
}
$gw['payment_method_settings'] = 1;
$gw['whish_payment_method'] = 1;
$gw['whish_mode'] = 'demo';
$c->payment_gateways = $gw;
$c->save();
echo "Whish switched on in DEMO mode for Lebanon (no real money).\n";
