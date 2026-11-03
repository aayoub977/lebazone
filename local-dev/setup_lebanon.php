<?php
// Local-dev: turn the seeded default country (India) into Lebanon.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Country;
use App\Models\Region;
use App\Services\LanguageService;

$country = Country::whereRaw('UPPER(code) = ?', ['LB'])->first()
    ?? Country::whereRaw('UPPER(code) = ?', ['IN'])->first()
    ?? new Country();

$country->name = 'Lebanon';
$country->dial_code = '+961';
$country->min_mobile_length = 7;   // 3 xxxxxx
$country->max_mobile_length = 8;   // 70/71/76/78/79/81 xxxxxx
$country->code = 'LB';
$country->currency = '$';
$country->currency_code = 'USD';
$country->decimal_point = 2;
$country->date_format = 'd-m-Y';
$country->time_format = 'h:i A';
$country->timezone = 'Asia/Beirut';
$country->status = 1;
$country->is_default = 1;
// Custom second display currency: 1 USD = 90,000 L.L. (editable in admin → Countries)
if (\Illuminate\Support\Facades\Schema::hasColumn('countries', 'secondary_exchange_rate')) {
    $country->secondary_currency = 'L.L.';
    $country->secondary_currency_code = 'LBP';
    if (empty($country->secondary_exchange_rate)) {
        $country->secondary_exchange_rate = 90000;
    }
    $country->secondary_decimal_point = 0;
}
$country->save();

$lang = app(LanguageService::class)->getDefaultLanguage();
if ($lang) {
    $country->saveTranslation($lang->id, ['name' => 'Lebanon']);
}

// Replace India's states with Lebanon's 8 governorates.
foreach (Region::where('country_id', $country->id)->get() as $r) {
    if (strtoupper(substr((string) $r->code, 0, 3)) !== 'LB-') { $r->delete(); }
}
$n = Region::importFromDataset((int) $country->id, 'LB', null, $lang?->id);

echo "Default country is now Lebanon (USD, +961, Asia/Beirut, 1 USD = " . number_format((float) $country->secondary_exchange_rate) . " L.L.). Governorates imported: $n\n";
