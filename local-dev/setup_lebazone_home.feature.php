<?php
// Lebazone: once - rebuilds the eCommerce home page (Home Builder) with:
//   1. Banner slider - 4 Lebazone slides that move by themselves (every 4.5 s, loops)
//   2. "Shop by category" - every active main category as big tiles (new ones appear by themselves)
//   3. New arrivals, On sale, Best sellers
//   4. One row per main category that exists (Ramadan, Kitchen appliances, Electronics)
//   5. Recently viewed (only for shoppers who looked at products)
// Rows with no products are hidden on the website automatically.
// Written to BOTH draft and published, so the editor and the website match. Flag lebazone_home_v1.
// Afterwards it is a normal Home Builder layout: change pictures/links/rows in the admin.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Language;
use App\Models\SupportedLanguage;
use App\Services\LanguageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (!Schema::hasTable('home_layouts')) return;
if (DB::table('settings')->where('variable', 'lebazone_home_v1')->value('value') === '1') return;

try {
    $lang = app(LanguageService::class)->getDefaultLanguage();
    $en = $lang ? (int) $lang->id : 0;
    $arSl = SupportedLanguage::where('code', 'ar')->first();
    $ar = $arSl ? (int) Language::where('supported_language_id', $arSl->id)->where('system_type', 4)->value('id') : 0;
    $t = function (string $english, string $arabic) use ($en, $ar) {
        $m = [$en => $english];
        if ($ar && $ar !== $en) $m[$ar] = $arabic;
        return $m;
    };

    // slide pictures -> storage/app/public/home_builder/
    $dir = $base . '/storage/app/public/home_builder';
    @mkdir($dir, 0777, true);
    foreach (glob(__DIR__ . '/brand-assets/home/*.jpg') ?: [] as $f) copy($f, $dir . '/' . basename($f));

    // main categories, found by slug or name
    $findCat = function (array $words) {
        foreach ($words as $w) {
            $id = DB::table('categories')->where('status', 1)
                ->where(fn ($q) => $q->where('slug', 'like', "%$w%")->orWhere('name', 'like', "%$w%"))
                ->orderByRaw('CASE WHEN parent_id IS NULL OR parent_id = 0 THEN 0 ELSE 1 END')
                ->orderBy('id')->value('id');
            if ($id) return (int) $id;
        }
        return 0;
    };
    $ramadan = $findCat(['ramadan']);
    $kitchen = $findCat(['kitchen']);
    $electro = $findCat(['electronic', 'phone']);

    $n = 0;
    $id = function (string $p) use (&$n) { $n++; return $p . '-lbz' . $n; };
    $corners = fn (int $r) => ['top_left' => $r, 'top_right' => $r, 'bottom_left' => $r, 'bottom_right' => $r];
    $all = ['app' => true, 'tablet' => true, 'web' => true];
    $section = function (string $type, array $config, array $extra = []) use ($id, $corners, $all) {
        $block = array_merge([
            'id' => $id('blk'), 'type' => $type, 'layout' => 'horizontal',
            'image' => ['app' => '', 'web' => '', 'tablet' => ''],
            'config' => $config, 'items' => [],
        ], $extra);
        return [
            'id' => $id('sec'), 'type' => $type, 'active' => true,
            'margin_top' => 10, 'margin_bottom' => 10,
            'border_radius' => $corners($type === 'banner_slider' ? 16 : 0), 'border_radius_custom' => false,
            'platforms' => $all, 'blocks' => [$block],
        ];
    };
    $slider = function (array $title, string $source, array $more = []) use ($section) {
        return $section('product_slider', array_merge([
            'variant' => 'with_title', 'section_title' => $title, 'section_subtitle' => [],
            'data_source' => $source, 'limit' => 12, 'shuffle_products' => false,
            'slider_category_ids' => [], 'manual_product_ids' => [], 'brand_ids' => [],
            'grid_columns' => ['app' => 2, 'tablet' => 3, 'web' => 5],
            'product_grid_gap' => ['app' => 8, 'tablet' => 12, 'web' => 16],
            'product_card_radius' => 12, 'block_padding' => 0,
            'image_aspect' => ['app' => '1:1', 'tablet' => '1:1', 'web' => '1:1'],
        ], $more));
    };

    // ---- 1. moving banners ----
    $slide = function (string $key, int $catId) {
        $web = "home_builder/lebazone-slide-$key-web.jpg";
        return [
            'image' => ['web' => $web, 'tablet' => $web, 'app' => "home_builder/lebazone-slide-$key-app.jpg"],
            'redirect_type' => $catId ? 'category' : 'url',
            'redirect_id' => $catId ? (string) $catId : '',
            'redirect_url' => $catId ? '' : '/products',
        ];
    };
    $items = [$slide('1-welcome', 0)];
    if ($ramadan) $items[] = $slide('2-ramadan', $ramadan);
    $items[] = $slide('3-electronics', $electro);
    $items[] = $slide('4-kitchen', $kitchen);

    $sections = [];
    $sections[] = $section('banner_slider', [
        'carousel_style' => 'full_width', 'indicator' => 'dots', 'auto_scroll' => true,
        'infinite_loop' => true, 'speed_ms' => 4500, 'center_focus' => false,
        'image_aspect' => ['app' => '16:9', 'tablet' => '3:1', 'web' => '3:1'],
    ], ['items' => $items]);

    // ---- 2. shop by category ----
    $sections[] = $section('category_section', [
        'variant' => 'with_title', 'section_title' => $t('Shop by category', 'تسوّق حسب الفئة'),
        'all_categories' => true, 'category_ids' => [],
        'grid_columns' => ['app' => 4, 'tablet' => 6, 'web' => 8],
        'category_gap' => ['app' => 10, 'tablet' => 14, 'web' => 18],
        'category_radius' => ['app' => 18, 'tablet' => 20, 'web' => 22],
    ], ['layout' => 'grid']);

    // ---- 3. product rows ----
    $sections[] = $slider($t('New arrivals', 'وصل حديثاً'), 'new_arrivals');
    $sections[] = $slider($t('On sale', 'عروض وتخفيضات'), 'discounted');
    $sections[] = $slider($t('Best sellers', 'الأكثر مبيعاً'), 'top_selling');
    if ($ramadan) $sections[] = $slider($t('Ramadan collection', 'تشكيلة رمضان'), 'category', ['slider_category_ids' => [$ramadan]]);
    if ($electro) $sections[] = $slider($t('Phone parts & accessories', 'قطع وإكسسوارات الهواتف'), 'category', ['slider_category_ids' => [$electro]]);
    if ($kitchen) $sections[] = $slider($t('Kitchen appliances', 'أجهزة المطبخ'), 'category', ['slider_category_ids' => [$kitchen]]);
    $sections[] = $slider($t('Recently viewed', 'شوهدت مؤخراً'), 'recently_visited', ['limit' => 10]);

    $rows = DB::table('home_layouts')->where('mode', 'ecommerce')->get();
    if ($rows->isEmpty()) {
        fwrite(STDERR, "  ! Lebazone home: no eCommerce home layout found - open Home Builder once, then this runs again.\n");
        exit(1);
    }
    $done = 0;
    foreach ($rows as $row) {
        $old = json_decode((string) ($row->published_json ?: $row->draft_json), true) ?: [];
        // keep the header colours the shop already chose; replace only the sections
        $config = array_merge([
            'background_theme' => 'color', 'background_color' => '#FFFFFF',
            'background_image_url' => ['app' => '', 'web' => '', 'tablet' => ''],
            'text_color' => '#0F172A', 'header_icon_url' => '',
        ], array_diff_key($old, ['sections' => 1]));
        $config['sections'] = $sections;
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $upd = ['draft_json' => $json, 'published_json' => $json, 'updated_at' => now()];
        foreach (['status' => 'published', 'is_active' => 1, 'published_at' => now()] as $c => $v) {
            if (Schema::hasColumn('home_layouts', $c)) $upd[$c] = $v;
        }
        DB::table('home_layouts')->where('id', $row->id)->update($upd);
        $done++;
    }

    DB::table('settings')->updateOrInsert(['variable' => 'lebazone_home_v1'], ['value' => '1']);
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    try { \Illuminate\Support\Facades\Artisan::call('config:cache'); } catch (\Throwable $e) {}
    echo "Home page: " . count($items) . " moving banners, Shop by category and " . (count($sections) - 2) . " product rows written to $done layout(s).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "  ! Lebazone home failed: " . $e->getMessage() . "\n");
    exit(1);
}
