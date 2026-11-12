<?php
// Lebazone: once - better blog posts (2026-11-13):
//   - cover pictures are now made from REAL Lebazone product photos (no more drawings)
//   - the "phone charger" post becomes "how to choose an electric heater" and the "Ramadan"
//     post becomes "grooming at home" - topics that match what the shop actually sells.
// Texts: local-dev/blog_faq_lb_texts.php. Flag lebazone_blog_faq_v2.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LanguageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::table('settings')->where('variable', 'lebazone_blog_faq_v2')->value('value') === '1') return;
if (!Schema::hasTable('blogs')) return;

// ---------- cover picture from product photos (GD) ----------
function lbz_round_rect($im, int $x, int $y, int $w, int $h, int $r, int $col): void
{
    imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $col);
    imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $col);
    foreach ([[$x + $r, $y + $r], [$x + $w - $r, $y + $r], [$x + $r, $y + $h - $r], [$x + $w - $r, $y + $h - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $col);
    }
}

function lbz_cover(array $photos, string $out, string $bgHex): bool
{
    if (!function_exists('imagecreatetruecolor') || !$photos) return false;
    $W = 1200; $H = 630;
    $im = imagecreatetruecolor($W, $H);
    [$r, $g, $b] = sscanf(ltrim($bgHex, '#'), '%02x%02x%02x');
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    $shadow = imagecolorallocatealpha($im, 0, 0, 0, 112);
    $white = imagecolorallocate($im, 255, 255, 255);
    $n = count($photos);
    $cw = $n >= 3 ? 330 : ($n === 2 ? 430 : 560);
    $ch = 500; $gap = 36;
    $x = (int) (($W - ($n * $cw + ($n - 1) * $gap)) / 2);
    $y = (int) (($H - $ch) / 2) - 8;
    foreach ($photos as $i => $file) {
        $src = @imagecreatefromstring((string) @file_get_contents($file));
        $cx = $x + $i * ($cw + $gap);
        // middle card a little higher when there are 3 - looks less flat
        $cy = ($n >= 3 && $i === 1) ? $y - 14 : $y + ($n >= 3 ? 10 : 0);
        lbz_round_rect($im, $cx + 6, $cy + 10, $cw, $ch, 26, $shadow);
        lbz_round_rect($im, $cx, $cy, $cw, $ch, 26, $white);
        if (!$src) continue;
        $sw = imagesx($src); $sh = imagesy($src);
        $pad = 22; $bw = $cw - 2 * $pad; $bh = $ch - 2 * $pad;
        $k = min($bw / $sw, $bh / $sh);
        $dw = (int) round($sw * $k); $dh = (int) round($sh * $k);
        imagecopyresampled($im, $src, $cx + (int) (($cw - $dw) / 2), $cy + (int) (($ch - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);
    }
    @mkdir(dirname($out), 0777, true);
    $ok = imagejpeg($im, $out, 88);
    imagedestroy($im);
    return $ok;
}

function lbz_product_photo(string $base, string $name): ?string
{
    $pid = DB::table('products')->whereNull('deleted_at')->whereRaw('TRIM(name) = ?', [trim($name)])->value('id')
        ?: DB::table('products')->whereNull('deleted_at')->where('name', 'like', '%' . trim($name) . '%')->orderByDesc('id')->value('id');
    if (!$pid) return null;
    $q = DB::table('product_variants')->where('product_id', $pid)->whereNotNull('image')->where('image', '!=', '');
    if (Schema::hasColumn('product_variants', 'deleted_at')) $q->whereNull('deleted_at');
    $img = $q->orderBy('sort_order')->orderBy('id')->value('image');
    $file = $img ? $base . '/storage/app/public/' . ltrim($img, '/') : null;
    return ($file && is_file($file)) ? $file : null;
}

try {
    $d = require __DIR__ . '/blog_faq_lb_texts.php';
    $lang = app(LanguageService::class)->getDefaultLanguage();
    $lid = $lang ? (int) $lang->id : 0;
    $now = now();
    $bg = [
        'how-ordering-on-lebazone-works' => '#FEE2E2',
        'choosing-the-right-heater-for-winter' => '#FFEDD5',
        'kitchen-appliances-that-save-you-time' => '#FEF3C7',
        'keep-insects-out-window-and-door-screens' => '#DCFCE7',
        'grooming-at-home-trimmers-shavers-hair-tools' => '#E0E7FF',
    ];
    $oldSlug = [
        'choosing-the-right-heater-for-winter' => 'choosing-the-right-phone-charger-and-cable',
        'grooming-at-home-trimmers-shavers-hair-tools' => 'ramadan-at-home-decorating-ideas',
    ];

    // categories
    $catIds = [];
    foreach ($d['categories'] as $slug => $name) {
        $id = DB::table('blog_categories')->where('slug', $slug)->value('id');
        if (!$id) {
            $id = DB::table('blog_categories')->insertGetId(['name' => $name, 'slug' => $slug, 'meta_title' => "$name - Lebazone blog", 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
            if ($lid && Schema::hasTable('blog_category_translations')) {
                DB::table('blog_category_translations')->insert(['blog_category_id' => $id, 'language_id' => $lid, 'name' => $name, 'meta_title' => "$name - Lebazone blog", 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $catIds[$slug] = (int) $id;
    }

    $covers = 0; $posts = 0;
    foreach (array_reverse($d['blogs']) as $i => $b) {
        // cover from real product photos
        $photos = array_values(array_filter(array_map(fn ($n) => lbz_product_photo($base, $n), $b['products'] ?? [])));
        $imgRel = 'blogs/' . $b['image'];
        if ($photos && lbz_cover($photos, $base . '/storage/app/public/' . $imgRel, $bg[$b['slug']] ?? '#F3F4F6')) $covers++;

        $fields = [
            'title' => $b['title'], 'slug' => $b['slug'], 'category_id' => $catIds[$b['cat']],
            'image' => $imgRel, 'description' => $b['body'], 'short_description' => $b['short'],
            'meta_title' => $b['title'] . ' | Lebazone', 'meta_description' => $b['short'], 'status' => 1, 'updated_at' => $now,
        ];
        $id = DB::table('blogs')->where('slug', $b['slug'])->value('id')
            ?: (isset($oldSlug[$b['slug']]) ? DB::table('blogs')->where('slug', $oldSlug[$b['slug']])->value('id') : null);
        if ($id) {
            DB::table('blogs')->where('id', $id)->update($fields);
        } else {
            $id = DB::table('blogs')->insertGetId($fields + ['tags' => '', 'created_at' => $now->copy()->addSeconds($i)]);
        }
        if ($lid && Schema::hasTable('blog_translations')) {
            $t = ['title' => $b['title'], 'description' => $b['body'], 'short_description' => $b['short'],
                  'meta_title' => $b['title'] . ' | Lebazone', 'meta_description' => $b['short'], 'updated_at' => $now];
            $row = DB::table('blog_translations')->where('blog_id', $id)->where('language_id', $lid)->first();
            if ($row) DB::table('blog_translations')->where('id', $row->id)->update($t);
            else DB::table('blog_translations')->insert($t + ['blog_id' => $id, 'language_id' => $lid, 'created_at' => $now]);
            // other languages: drop the old (charger / Ramadan) wording so they show the new post
            DB::table('blog_translations')->where('blog_id', $id)->where('language_id', '!=', $lid)->delete();
        }
        $posts++;
    }

    // categories left without posts (Tech Tips, Seasonal) are hidden
    foreach (['tech-tips', 'seasonal'] as $slug) {
        $cid = DB::table('blog_categories')->where('slug', $slug)->value('id');
        if ($cid && !DB::table('blogs')->where('category_id', $cid)->exists()) {
            DB::table('blog_categories')->where('id', $cid)->update(['status' => 0, 'updated_at' => $now]);
        }
    }

    DB::table('settings')->updateOrInsert(['variable' => 'lebazone_blog_faq_v2'], ['value' => '1']);
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    try { \Illuminate\Support\Facades\Artisan::call('config:cache'); } catch (\Throwable $e) {}
    echo "Blog v2: $posts post(s) updated, $covers cover picture(s) made from product photos.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "  ! Blog v2 failed: " . $e->getMessage() . "\n");
    exit(1);
}
