<?php
// Brand kit, step 2 (database): turns the copied Hader database into this store's own:
// clears Hader's shop data, sets the country + payment methods, and creates one
// standard-delivery zone, one store, and the brand's catalogue (no quick/same-day mode).
// Run from <store>\local-dev by make-brand.bat.  Pass --reset to clear and rebuild again.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\API\ProductApisController;
use App\Http\Controllers\API\StoreApiController;
use App\Http\Controllers\API\ZoneApiController;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Setting;
use App\Models\Store;
use App\Models\SupportedLanguage;
use App\Models\User;
use App\Models\Zone;
use App\Services\LanguageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

try {
$b = json_decode(file_get_contents(__DIR__ . '/brand.json'), true) or die("brand.json missing\n");
$catalog = json_decode(file_get_contents(__DIR__ . '/brand-assets/catalog.json'), true) or die("brand-assets/catalog.json missing\n");
$N = $b['name'];
$isUAE = $b['country'] === 'AE';
$reset = in_array('--reset', $argv ?? [], true);

function call_api($controller, string $method, array $data): array
{
    $res = app($controller)->$method(Request::create('/', 'POST', $data));
    $out = $res->getData(true);
    if (empty($out['status'])) {
        $msg = $out['message'] ?? null;
        fwrite(STDERR, "  ! {$method} failed: " . (is_string($msg) ? $msg : json_encode($out)) . "\n");
        exit(1);
    }
    return $out['data'] ?? [];
}
function setting(string $k, $v): void
{
    DB::table('settings')->updateOrInsert(['variable' => $k], ['value' => (string) $v]); // no timestamps
}

echo "Setting up the $N database...\n";
$done = Setting::where('variable', 'brand_setup_done')->value('value') === $b['key'];

// ---------- 1. Clear Hader's shop data (first run only, or with --reset) ----------
if (!$done || $reset) {
    $tables = [
        // catalogue
        'products', 'product_translations', 'product_images', 'product_variants', 'product_variant_translations',
        'product_variant_attribute_values', 'product_variant_store_stocks', 'product_variant_custom_values',
        'product_variant_custom_value_translations', 'product_ratings', 'rating_images', 'product_recommendations',
        'product_stock_alerts', 'product_bulk_imports', 'categories', 'category_translations', 'category_attribute',
        'brands', 'brand_translations', 'flash_sales', 'flash_sale_items', 'flash_sale_price_backups', 'offers',
        // places
        'zones', 'zone_translations', 'stores', 'store_translations', 'delivery_areas', 'delivery_cities',
        // orders & money
        'orders', 'order_items', 'order_item_taxes', 'order_statuses', 'transactions', 'return_requests',
        'local_payment_sessions', 'live_tracking', 'conversations', 'messages', 'seller_ledger', 'sellers',
        'delivery_boy_cash_collections', 'delivery_boy_settlements', 'delivery_boy_salaries', 'withdrawal_requests',
        // customers
        'carts', 'cart_notifications', 'favorites', 'recently_visited_products', 'user_addresses', 'user_wallets',
        'wallet_transactions', 'loyalty_point_transactions', 'sms_verifications', 'notifications', 'user_tokens',
        'spin_wheel_spins', 'jobs', 'failed_jobs',
    ];
    Schema::disableForeignKeyConstraints();
    $n = 0;
    foreach ($tables as $t) {
        if (Schema::hasTable($t)) {
            DB::table($t)->truncate();
            $n++;
        }
    }
    // Store-owner logins belonged to Hader's stores.
    if (Schema::hasColumn('admins', 'store_id')) {
        $ownerIds = DB::table('admins')->whereNotNull('store_id')->where('store_id', '>', 0)->pluck('id');
        if ($ownerIds->isNotEmpty()) {
            if (Schema::hasTable('model_has_roles')) {
                DB::table('model_has_roles')->whereIn('model_id', $ownerIds)->where('model_type', 'like', '%Admin')->delete();
            }
            DB::table('admins')->whereIn('id', $ownerIds)->delete();
        }
    }
    DB::table('users')->delete();                                    // Hader's customers
    DB::table('home_layouts')->whereNotNull('zone_id')->delete();     // layouts tied to Hader's zones
    Schema::enableForeignKeyConstraints();
    echo "  - cleared Hader's catalogue, zones, stores, orders and customers ($n tables)\n";
}

// ---------- 2. Country, currency, payment methods ----------
$country = Country::whereRaw('UPPER(code) = ?', [$b['country']])->first();
if (!$country) {
    fwrite(STDERR, "  ! Country {$b['country']} missing - the setup scripts should have created it.\n");
    exit(1);
}
Country::where('id', '!=', $country->id)->update(['is_default' => 0, 'status' => 0]);
$country->is_default = 1;
$country->status = 1;
if (!$b['secondary_currency'] && Schema::hasColumn('countries', 'secondary_currency')) {
    $country->secondary_currency = null;
    $country->secondary_currency_code = null;
    $country->secondary_exchange_rate = null;
}
$gw = is_array($country->payment_gateways) ? $country->payment_gateways : [];
foreach ($gw as $k => $v) {
    if (is_string($k) && str_ends_with($k, '_payment_method')) {
        $gw[$k] = '0';                                                // everything off...
    }
}
$want = $b['payments'];
if (($gw['whish_channel'] ?? '') !== '') {
    unset($want['whish_mode']);                                       // real Whish keys entered: keep their mode
}
$country->payment_gateways = array_map(fn ($v) => is_int($v) ? (string) $v : $v,
        array_merge($gw, ['payment_method_settings' => 1], $want));   // ...this store's set on
$country->save();
$on = [];
foreach ($b['payments'] as $k => $v) {
    if (str_ends_with($k, '_payment_method') && $v) {
        $on[] = str_replace('_payment_method', '', $k);
    }
}
echo "  - country {$country->name} ({$country->currency_code}); payments: " . implode(', ', $on) . "\n";

setting('quick_commerce_enabled', 0);   // standard delivery only
setting('phone_login', 1);              // phone + password login for testing
setting('phone_auth_password', 1);

// ---------- 3. Languages ----------
$lang = app(LanguageService::class)->getDefaultLanguage() or die("No default admin language.\n");
$langId = (int) $lang->id;
$arSl = SupportedLanguage::where('code', 'ar')->first();
$arLangId = $arSl ? (int) Language::where('supported_language_id', $arSl->id)->where('system_type', 4)->value('id') : 0;

// ---------- 4. Zone (standard delivery only) ----------
$polygons = [
    'lebanon' => [
        [34.7300, 35.8500], [34.7000, 36.1000], [34.6600, 36.4500], [34.5200, 36.6300], [34.2000, 36.6300],
        [34.0000, 36.4600], [33.8300, 36.2600], [33.6000, 36.0600], [33.4500, 35.9600], [33.3000, 35.8300],
        [33.2700, 35.6300], [33.0800, 35.5200], [33.0800, 35.1000], [33.1000, 35.0300], [33.6000, 35.2300],
        [34.0000, 35.4800], [34.4000, 35.6800],
    ],
    // Dubai emirate, generous on the sea side (Palm, islands).
    'dubai' => [
        [25.3600, 55.2700], [25.3200, 55.4200], [25.2400, 55.5600], [25.0500, 55.6500], [24.8300, 55.5200],
        [24.7800, 55.2000], [24.8800, 54.9000], [25.0000, 54.8800], [25.1300, 55.0000], [25.2400, 55.0800],
        [25.3200, 55.1800],
    ],
];
$poly = json_encode(array_map(fn ($p) => ['lat' => $p[0], 'lng' => $p[1]], $polygons[$b['zone']['polygon']]));
$zone = Zone::where('country_id', $country->id)->where('status', 1)->first();
if (!$zone) {
    $d = $b['delivery'];
    $z = call_api(ZoneApiController::class, 'save', [
        'language_id' => $langId, 'name' => $b['zone']['name'], 'city' => $b['zone']['city'], 'state' => $b['zone']['state'],
        'country_id' => $country->id, 'status' => 1, 'sales_channel' => 'ecommerce',
        'polygon_boundary_ecommerce' => $poly,
        'ecommerce_pricing_strategy' => 'flat', 'ecommerce_default_delivery_charge' => $d['flat'],
        'ecommerce_flat_delivery_charge' => $d['flat'], 'ecommerce_flat_free_delivery_above' => $d['free_above'],
        'ecommerce_free_delivery_threshold' => $d['free_above'], 'ecommerce_delivery_days' => $d['days'],
    ]);
    $zone = Zone::find($z['id']);
    echo "  - delivery zone: {$zone->name} (standard delivery: {$d['flat']} flat, free above {$d['free_above']}, {$d['days']} day(s))\n";
}

// Existing drivers now deliver in this zone.
if (Schema::hasTable('delivery_boys')) {
    $nd = DB::table('delivery_boys')->update(['zone_id' => $zone->id, 'country_id' => $country->id]);
    if ($nd) {
        echo "  - {$nd} driver(s) moved to {$zone->name}\n";
    }
}

// ---------- 5. Store ----------
$s = $b['store'];
$store = Store::where('zone_id', $zone->id)->first();
if (!$store) {
    $r = call_api(StoreApiController::class, 'save', [
        'language_id' => $langId, 'name' => $s['name'], 'provider' => $N,
        'fulfillment_type' => 'ecommerce', 'service_modes' => 'delivery', 'zone_id' => $zone->id,
        'address' => $s['address'], 'formatted_address' => $s['address'],
        'latitude' => $s['lat'], 'longitude' => $s['lng'], 'contact_number' => $s['phone'],
        'email' => $s['email'], 'password' => $s['password'], 'confirm_password' => $s['password'],
        'preparation_time' => 30, 'status' => 1,
    ]);
    $store = Store::find($r['id']);
    echo "  - store: {$store->name}\n";
}

// ---------- 6. Catalogue ----------
$catIds = [];
foreach ($catalog['categories'] as $i => $c) {
    $slug = $b['key'] . '-' . $c['key'];
    $cat = Category::where('slug', $slug)->first() ?? new Category();
    if (!$cat->exists) {
        $cat->slug = $slug;
        $cat->name = $c['name'];
        $cat->image = 'catalog/cat-' . $c['key'] . '.png';
        $cat->status = 1;
        $cat->parent_id = 0;
        $cat->row_order = $i + 1;
        $cat->meta_title = $cat->meta_keywords = $cat->schema_markup = $cat->meta_description = '';
        $cat->save();
    }
    $cat->saveTranslation($langId, ['name' => $c['name']]);
    if ($arLangId) {
        $cat->saveTranslation($arLangId, ['name' => $c['ar']]);
    }
    $catIds[$c['key']] = $cat->id;
}
$brandIds = [];
$brandNames = [];
foreach ($catalog['brands'] as $br) {
    $brand = Brand::where('name', $br['name'])->first();
    if (!$brand) {
        $brand = new Brand();
        $brand->name = $br['name'];
        $brand->status = 1;
        $brand->image = 'catalog/brand-' . $br['key'] . '.png';
        $brand->save();
        $brand->saveTranslation($langId, ['name' => $br['name']]);
    }
    $brandIds[$br['key']] = $brand->id;
    $brandNames[$br['key']] = $br['name'];
}
$where = $isUAE ? 'the UAE' : 'Lebanon';
$made = 0;
foreach ($catalog['products'] as $p) {
    if (DB::table('product_variants')->where('sku', $p['sku'])->exists()) {
        continue;
    }
    $bn = $brandNames[$p['brand']];
    $desc = "<p>{$p['name']} by {$bn}. Delivered to your door anywhere in $where by $N.</p>";
    $trans = [$langId => ['name' => $p['name'], 'description' => $desc, 'short_description' => $p['name'], 'manufacturer' => $bn]];
    $vTrans = [$langId => ['name' => $p['name']]];
    if ($arLangId) {
        $trans[$arLangId] = ['name' => $p['ar'], 'description' => "<p>{$p['ar']}</p>", 'short_description' => $p['ar']];
        $vTrans[$arLangId] = ['name' => $p['ar']];
    }
    call_api(ProductApisController::class, 'save', [
        'language_id' => $langId, 'category_id' => $catIds[$p['cat']], 'brand_id' => $brandIds[$p['brand']],
        'sales_channel' => 'ecommerce', 'product_type' => 0, 'status' => 1, 'is_draft' => 0,
        'return_status' => 1, 'return_days' => $isUAE ? 14 : 7, 'cancelable_status' => 1,
        'till_status_quick' => 2, 'till_status_ecommerce' => 2, 'cod_allowed' => 1, 'total_allowed_quantity' => 10,
        'translations' => json_encode($trans, JSON_UNESCAPED_UNICODE),
        'variants' => json_encode([[
            'name' => $p['name'], 'sku' => $p['sku'], 'translations' => $vTrans,
            'image_path' => 'catalog/' . strtolower($p['sku']) . '.png',
            'gallery_paths' => [],
            'store_stocks' => [[
                'store_id' => $store->id, 'is_listed' => 1, 'stock_status' => 1,
                'is_unlimited_stock' => 0, 'available' => rand(30, 150), 'min_alert' => 5,
                'price' => $p['price'], 'discounted_price' => $p['sale'], 'purchase_price' => round($p['sale'] * 0.7, 2),
            ]],
        ]], JSON_UNESCAPED_UNICODE),
    ]);
    $made++;
}
echo '  - catalogue: ' . count($catIds) . ' categories, ' . count($brandIds) . " brands, $made new products\n";

// ---------- 7. Home page: brand-tinted header; sections no longer point at Hader's old items ----------
$fix = function ($node) use (&$fix, $b) {
    if (!is_array($node)) {
        return $node;
    }
    foreach (['color', 'background_color'] as $k) {
        if (isset($node[$k]) && is_string($node[$k]) && in_array(strtoupper($node[$k]), ['#FFE94B', '#0369A1', '#FFF7ED'], true)) {
            $node[$k] = $b['home_bg'];
        }
    }
    foreach (['category_ids', 'slider_category_ids', 'brand_ids', 'manual_product_ids'] as $k) {
        if (isset($node[$k]) && is_array($node[$k])) {
            $node[$k] = [];
        }
    }
    if (isset($node['category_id']) && !is_array($node['category_id'])) {
        $node['category_id'] = '';
    }
    foreach ($node as $k => $v) {
        if (is_array($v)) {
            $node[$k] = $fix($v);
        }
    }
    return $node;
};
foreach (DB::table('home_layouts')->get() as $row) {
    $upd = [];
    foreach (['draft_json', 'published_json', 'category_layouts_draft', 'category_layouts_published'] as $col) {
        if (!empty($row->$col)) {
            $upd[$col] = json_encode($fix(json_decode($row->$col, true)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    foreach (['category_ids', 'category_ids_quick', 'category_ids_ecommerce'] as $col) {
        if (property_exists($row, $col) && $row->$col !== null) {
            $upd[$col] = json_encode(array_values($catIds));
        }
    }
    if ($upd) {
        DB::table('home_layouts')->where('id', $row->id)->update($upd);
    }
}
echo "  - home page colours and sections updated\n";

// ---------- 8. Test customer ----------
$t = $b['test_customer'];
$user = User::withTrashed()->where('mobile', $t['mobile'])->where('country_code', $t['cc'])->first() ?? new User();
if (method_exists($user, 'trashed') && $user->trashed()) {
    $user->restore();
}
$user->name = $user->name ?: 'Test Customer';
$user->email = "test.customer@{$b['key']}.local";
$user->country_code = $t['cc'];
$user->mobile = $t['mobile'];
$user->country_id = $country->id;
$user->type = 'phone';
$user->is_verified = 1;
$user->status = 1;
$user->password = bcrypt($t['password']);
$user->referral_code = $user->referral_code ?: strtoupper(Str::random(8));
$user->language_id = $user->language_id ?: \App\Helpers\CommonHelper::getDefaultLanguageId();
$user->save();
echo "  - test customer: {$t['cc']} {$t['mobile']} / {$t['password']}\n";

setting('brand_setup_done', $b['key']);
if (!$done) {
    @file_put_contents(__DIR__ . '/LOCAL_LOGINS.txt', "Test customer:  {$t['cc']} {$t['mobile']} / {$t['password']}  (choose 'Login with password')\n", FILE_APPEND);
}
echo "$N database ready.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "\n  ! Store setup failed: " . $e->getMessage() . "\n    at " . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
}
