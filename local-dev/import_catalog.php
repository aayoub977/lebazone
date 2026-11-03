<?php
// Lebazone: import / update the catalogue from local-dev\catalog\Lebazone-catalogue.xlsx.
// New SKUs are created, existing SKUs are updated (names, category, brand, prices, stock,
// photo, show/hide). Rows with problems are skipped and listed in catalog-import-log.txt.
$dev = __DIR__;
$base = $dev . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\API\ProductApisController;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\SupportedLanguage;
use App\Services\LanguageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

$file = $argv[1] ?? "$dev/catalog/Lebazone-catalogue.xlsx";
$imgDir = "$dev/catalog/images";
$store = "$base/storage/app/public/catalog";
$logFile = "$dev/catalog-import-log.txt";
$log = [];
$say = function (string $m) use (&$log) { echo $m . "\n"; $log[] = $m; };

if (!is_file($file)) {
    fwrite(STDERR, "Catalogue file not found: $file\n");
    exit(1);
}
@mkdir($store, 0777, true);

$lang = app(LanguageService::class)->getDefaultLanguage();
$langId = (int) $lang->id;
$arSl = SupportedLanguage::where('code', 'ar')->first();
$arLangId = $arSl ? (int) Language::where('supported_language_id', $arSl->id)->where('system_type', 4)->value('id') : 0;
$shop = Store::where('status', 1)->orderBy('id')->first();
if (!$shop) {
    fwrite(STDERR, "No active store found - run finish-setup.bat first.\n");
    exit(1);
}

/** Read a sheet into rows keyed by header text (header matched loosely: case/stars/brackets ignored). */
function sheetRows($book, string $name): array
{
    $ws = $book->getSheetByName($name);
    if (!$ws) return [];
    $raw = $ws->toArray(null, true, false, false);
    $head = array_map(fn ($h) => strtolower(trim(preg_replace('/\s+/', ' ', str_replace('*', '', (string) $h)))), array_shift($raw) ?? []);
    $out = [];
    foreach ($raw as $i => $r) {
        if (!array_filter($r, fn ($v) => trim((string) $v) !== '')) continue;
        $row = ['_row' => $i + 2];
        foreach ($head as $k => $h) if ($h !== '') $row[$h] = is_string($r[$k] ?? null) ? trim($r[$k]) : ($r[$k] ?? null);
        $out[] = $row;
    }
    return $out;
}

/** Copy an image from catalog\images (or keep one already uploaded); returns the storage path or null. */
function useImage(?string $name, string $imgDir, string $store): ?string
{
    $name = trim((string) $name);
    if ($name === '') return null;
    if (preg_match('#^https?://#i', $name)) {
        $data = @file_get_contents($name);
        if ($data === false) return null;
        $ext = strtolower(pathinfo(parse_url($name, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg';
        $fn = substr(md5($name), 0, 12) . '.' . $ext;
        file_put_contents("$store/$fn", $data);
        return "catalog/$fn";
    }
    $fn = basename(str_replace('\\', '/', $name));
    if (is_file("$imgDir/$fn")) {
        copy("$imgDir/$fn", "$store/$fn");
        return "catalog/$fn";
    }
    return is_file("$store/$fn") ? "catalog/$fn" : null;
}

$money = fn ($v) => $v === null || $v === '' ? null : round((float) preg_replace('/[^0-9.]/', '', (string) $v), 2);
$book = IOFactory::load($file);

// ---------- Categories ----------
$catIds = [];
foreach (sheetRows($book, 'Categories') as $i => $c) {
    $en = trim((string) ($c['category (english)'] ?? ''));
    if ($en === '') continue;
    $slug = 'lebazone-' . Str::slug($en);
    $cat = Category::where('slug', $slug)->first()
        ?? Category::where('name', $en)->first()
        ?? new Category();
    if (!$cat->exists) {
        $cat->slug = $slug;
        $cat->parent_id = 0;
        $cat->meta_title = $cat->meta_keywords = $cat->schema_markup = $cat->meta_description = '';
    }
    $cat->name = $en;
    $cat->status = 1;
    $cat->row_order = $i + 1;
    if ($img = useImage($c['image file'] ?? null, $imgDir, $store)) $cat->image = $img;
    elseif (!$cat->image) $cat->image = '';
    $cat->save();
    $cat->saveTranslation($langId, ['name' => $en]);
    $ar = trim((string) ($c['category (arabic)'] ?? ''));
    if ($arLangId && $ar !== '') $cat->saveTranslation($arLangId, ['name' => $ar]);
    $catIds[mb_strtolower($en)] = $cat->id;
}

// ---------- Brands ----------
$brandIds = [];
foreach (sheetRows($book, 'Brands') as $b) {
    $name = $b['brand'] ?? '';
    if ($name === '') continue;
    $brand = Brand::where('name', $name)->first() ?? new Brand();
    $brand->name = $name;
    $brand->status = 1;
    if ($img = useImage($b['logo file'] ?? null, $imgDir, $store)) $brand->image = $img;
    elseif (!$brand->image) $brand->image = '';
    $brand->save();
    $brand->saveTranslation($langId, ['name' => $name]);
    $brandIds[mb_strtolower($name)] = $brand->id;
}
$say('Categories: ' . count($catIds) . ', brands: ' . count($brandIds));

// ---------- Products ----------
// Custom (Lebazone): Size column (S/M/L/XL) -> products.lbz_size, used by the Order Station box guess.
$setSize = function (string $sku, array $p) {
    $size = strtoupper(trim((string) ($p['size (s/m/l/xl)'] ?? $p['size'] ?? '')));
    if (!in_array($size, ['S', 'M', 'L', 'XL'], true) || !\Illuminate\Support\Facades\Schema::hasColumn('products', 'lbz_size')) return;
    $pid = DB::table('product_variants')->where('sku', $sku)->value('product_id');
    if ($pid) DB::table('products')->where('id', $pid)->update(['lbz_size' => $size]);
};

$warAr = ['no warranty' => 'بدون كفالة', '1 month' => 'شهر واحد', '3 months' => '3 أشهر', '6 months' => '6 أشهر', '1 year' => 'سنة واحدة', '2 years' => 'سنتان'];
$added = $updated = $skipped = 0;
$seen = [];
foreach (sheetRows($book, 'Products') as $p) {
    $r = $p['_row'];
    $sku = strtoupper(trim((string) ($p['sku'] ?? '')));
    $name = trim((string) ($p['product name (english)'] ?? ''));
    $nameAr = trim((string) ($p['product name (arabic)'] ?? ''));
    $catName = mb_strtolower(trim((string) ($p['category'] ?? '')));
    $brandName = mb_strtolower(trim((string) ($p['brand'] ?? '')));
    $catLabel = trim((string) ($p['category'] ?? ''));
    $brandLabel = trim((string) ($p['brand'] ?? ''));
    $price = $money($p['price usd'] ?? null);
    $sale = $money($p['sale price usd'] ?? null);
    $cost = $money($p['cost usd (private)'] ?? null);
    $qty = (int) ($p['stock qty'] ?? 0);
    $war = mb_strtolower(trim((string) ($p['warranty'] ?? '')));
    $short = trim((string) ($p['short description (english)'] ?? ''));
    $descEn = trim((string) ($p['description (english)'] ?? ''));
    $descAr = trim((string) ($p['description (arabic)'] ?? ''));
    $show = strtolower(trim((string) ($p['show on store'] ?? 'yes'))) !== 'no';

    $err = null;
    if ($sku === '') $err = 'SKU is empty';
    elseif (isset($seen[$sku])) $err = "SKU $sku is used twice (also row {$seen[$sku]})";
    elseif ($name === '') $err = 'English name is empty';
    elseif (!isset($catIds[$catName])) $err = "category \"$catLabel\" is not in the Categories sheet";
    elseif ($brandName !== '' && !isset($brandIds[$brandName])) $err = "brand \"$brandLabel\" is not in the Brands sheet";
    elseif (!$price || $price <= 0) $err = 'price is missing';
    elseif ($sale !== null && $sale >= $price) $err = 'sale price must be lower than price';
    if ($err) { $say("  ! Row $r skipped: $err"); $skipped++; continue; }
    $seen[$sku] = $r;

    $image = useImage($p['main image file'] ?? null, $imgDir, $store);
    $gallery = [];
    foreach (preg_split('/\s*,\s*/', (string) ($p['extra image files (comma separated)'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $g) {
        if ($gp = useImage($g, $imgDir, $store)) $gallery[] = $gp;
    }
    $warLine = ($war && $war !== 'no warranty') ? "<p><strong>Warranty:</strong> $war</p>" : '';
    $warLineAr = ($war && $war !== 'no warranty') ? '<p><strong>الكفالة:</strong> ' . ($warAr[$war] ?? $war) . '</p>' : '';
    $html = fn ($t) => $t === '' ? '' : '<p>' . nl2br(e($t)) . '</p>';
    $descHtml = ($html($descEn) ?: $html($short ?: $name)) . $warLine;
    $descHtmlAr = $html($descAr ?: $nameAr) . $warLineAr;
    $brandId = $brandName !== '' ? $brandIds[$brandName] : 0;
    $salePrice = $sale ?? $price;
    $costPrice = $cost ?? round($salePrice * 0.7, 2);

    $variant = ProductVariant::where('sku', $sku)->first();
    if (!$variant) {
        if (!$image) { $say("  ! Row $r skipped: photo \"" . ($p['main image file'] ?? '') . "\" not found in catalog\\images"); $skipped++; unset($seen[$sku]); continue; }
        $trans = [$langId => ['name' => $name, 'description' => $descHtml, 'short_description' => $short ?: $name, 'manufacturer' => $p['brand'] ?? '']];
        $vTrans = [$langId => ['name' => $name]];
        if ($arLangId && $nameAr !== '') {
            $trans[$arLangId] = ['name' => $nameAr, 'description' => $descHtmlAr, 'short_description' => $nameAr];
            $vTrans[$arLangId] = ['name' => $nameAr];
        }
        $res = app(ProductApisController::class)->save(Request::create('/', 'POST', [
            'language_id' => $langId, 'category_id' => $catIds[$catName], 'brand_id' => $brandId,
            'sales_channel' => 'ecommerce', 'product_type' => 0, 'status' => $show ? 1 : 0, 'is_draft' => 0,
            'return_status' => 1, 'return_days' => 10, 'cancelable_status' => 1,
            'till_status_quick' => 2, 'till_status_ecommerce' => 2, 'cod_allowed' => 1, 'total_allowed_quantity' => 10,
            'translations' => json_encode($trans, JSON_UNESCAPED_UNICODE),
            'variants' => json_encode([[
                'name' => $name, 'sku' => $sku, 'translations' => $vTrans,
                'image_path' => $image, 'gallery_paths' => $gallery,
                'store_stocks' => [[
                    'store_id' => $shop->id, 'is_listed' => 1, 'stock_status' => $qty > 0 ? 1 : 0,
                    'is_unlimited_stock' => 0, 'available' => $qty, 'min_alert' => 3,
                    'price' => $price, 'discounted_price' => $salePrice, 'purchase_price' => $costPrice,
                ]],
            ]], JSON_UNESCAPED_UNICODE),
        ]));
        $out = $res->getData(true);
        if (empty($out['status'])) {
            $msg = is_string($out['message'] ?? null) ? $out['message'] : json_encode($out['message'] ?? $out);
            $say("  ! Row $r ($sku) not added: $msg"); $skipped++; continue;
        }
        $added++;
        $setSize($sku, $p);
        continue;
    }

    // ---- update an existing product ----
    DB::transaction(function () use ($variant, $p, $catIds, $catName, $brandId, $show, $name, $nameAr, $short, $descHtml, $descHtmlAr, $image, $gallery, $langId, $arLangId, $shop, $qty, $price, $salePrice, $costPrice) {
        $product = Product::find($variant->product_id);
        $product->category_id = $catIds[$catName];
        $product->brand_id = $brandId;
        $product->status = $show ? 1 : 0;
        if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'name')) $product->name = $name;
        $product->return_days = max(10, (int) $product->return_days);
        if ($image && \Illuminate\Support\Facades\Schema::hasColumn('products', 'image')) $product->image = $image;
        $product->save();
        $product->saveTranslation($langId, ['name' => $name, 'description' => $descHtml, 'short_description' => $short ?: $name, 'manufacturer' => $p['brand'] ?? '']);
        if ($arLangId && $nameAr !== '') {
            $product->saveTranslation($arLangId, ['name' => $nameAr, 'description' => $descHtmlAr, 'short_description' => $nameAr]);
        }
        $variant->name = $name;
        if ($image) $variant->image = $image;
        $variant->save();
        if ($gallery) {
            DB::table('product_images')->where('product_variant_id', $variant->id)->delete();
            foreach ($gallery as $g) DB::table('product_images')->insert(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'image' => $g, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('product_variant_store_stocks')->updateOrInsert(
            ['product_variant_id' => $variant->id, 'store_id' => $shop->id],
            ['is_listed' => 1, 'stock_status' => $qty > 0 ? 1 : 0, 'available' => $qty, 'price' => $price,
             'discounted_price' => $salePrice, 'purchase_price' => $costPrice, 'updated_at' => now()]
        );
    });
    $updated++;
    $setSize($sku, $p);
}

$say("Products: $added added, $updated updated, $skipped skipped.");
file_put_contents($logFile, date('Y-m-d H:i') . "\n" . implode("\n", $log) . "\n");
\Illuminate\Support\Facades\Artisan::call('optimize:clear');
