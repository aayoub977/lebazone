<?php
// Local-dev: switch on Arabic (RTL) for the website, customer app and delivery app.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Language;
use App\Models\SupportedLanguage;

$sl = SupportedLanguage::where('code', 'ar')->first();
if (!$sl) { echo "Arabic is missing from supported_languages\n"; exit(1); }

foreach ([Language::SYSTEM_TYPE_CUSTOMER_APP, Language::SYSTEM_TYPE_DELIVERY_BOY_APP, Language::SYSTEM_TYPE_WEBSITE] as $type) {
    $lang = Language::firstOrNew(['supported_language_id' => $sl->id, 'system_type' => $type]);
    $lang->display_name = 'العربية';
    $lang->slug = Language::slugForSupportedLanguage($sl->id);
    $lang->status = 1;
    if (!$lang->exists) { $lang->is_default = 0; }
    $lang->save();
}
echo "Arabic enabled for website, customer app and delivery app.\n";
