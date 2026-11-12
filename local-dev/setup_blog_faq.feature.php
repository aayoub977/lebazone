<?php
// Lebazone: once - adds 5 starting blog posts (with cover pictures) and 15 FAQs.
// Texts: local-dev/blog_faq_lb_texts.php, pictures: local-dev/brand-assets/blog/.
// Skips anything that already exists (same blog address / same question), so nothing you
// wrote is changed. Edit them later in admin -> Blogs and admin -> FAQs. Flag lebazone_blog_faq_v1.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LanguageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::table('settings')->where('variable', 'lebazone_blog_faq_v1')->value('value') === '1') return;
if (!Schema::hasTable('blogs') || !Schema::hasTable('faqs')) return;

try {
    $d = require __DIR__ . '/blog_faq_lb_texts.php';
    $lang = app(LanguageService::class)->getDefaultLanguage();
    $lid = $lang ? (int) $lang->id : 0;
    $now = now();
    $has = fn (string $t, string $c) => Schema::hasColumn($t, $c);

    // pictures -> storage/app/public/blogs/
    $dir = $base . '/storage/app/public/blogs';
    @mkdir($dir, 0777, true);
    foreach (glob(__DIR__ . '/brand-assets/blog/*.jpg') ?: [] as $f) copy($f, $dir . '/' . basename($f));

    // categories
    $catIds = [];
    foreach ($d['categories'] as $slug => $name) {
        $id = DB::table('blog_categories')->where('slug', $slug)->value('id');
        if (!$id) {
            $row = ['name' => $name, 'slug' => $slug, 'meta_title' => "$name - Lebazone blog", 'status' => 1, 'created_at' => $now, 'updated_at' => $now];
            $id = DB::table('blog_categories')->insertGetId($row);
            if ($lid && Schema::hasTable('blog_category_translations')) {
                DB::table('blog_category_translations')->insert(['blog_category_id' => $id, 'language_id' => $lid, 'name' => $name, 'meta_title' => "$name - Lebazone blog", 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $catIds[$slug] = (int) $id;
    }

    // blog posts (newest = first in the list, so the "how it works" guide shows on top)
    $added = 0;
    $posts = array_reverse($d['blogs']);
    foreach ($posts as $i => $b) {
        if (DB::table('blogs')->where('slug', $b['slug'])->exists()) continue;
        $row = [
            'title' => $b['title'], 'slug' => $b['slug'], 'category_id' => $catIds[$b['cat']],
            'image' => 'blogs/' . $b['image'], 'description' => $b['body'], 'short_description' => $b['short'],
            'tags' => '', 'meta_title' => $b['title'] . ' | Lebazone', 'meta_description' => $b['short'],
            'status' => 1, 'created_at' => $now->copy()->addSeconds($i), 'updated_at' => $now->copy()->addSeconds($i),
        ];
        if ($has('blogs', 'views_count')) $row['views_count'] = 0;
        $id = DB::table('blogs')->insertGetId($row);
        if ($lid && Schema::hasTable('blog_translations')) {
            DB::table('blog_translations')->insert([
                'blog_id' => $id, 'language_id' => $lid, 'title' => $b['title'], 'description' => $b['body'],
                'short_description' => $b['short'], 'meta_title' => $b['title'] . ' | Lebazone', 'meta_description' => $b['short'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $added++;
    }

    // FAQs
    $faqs = 0;
    $sort = (int) (DB::table('faqs')->max($has('faqs', 'sort_order') ? 'sort_order' : 'id') ?? 0);
    foreach ($d['faqs'] as [$q, $a]) {
        if (DB::table('faqs')->where('question', $q)->exists()) continue;
        $row = ['question' => $q, 'answer' => $a, 'status' => '1'];
        if ($has('faqs', 'created_at')) { $row['created_at'] = $now; $row['updated_at'] = $now; }
        if ($has('faqs', 'sort_order')) $row['sort_order'] = ++$sort;
        $id = DB::table('faqs')->insertGetId($row);
        if ($lid && Schema::hasTable('faq_translations')) {
            DB::table('faq_translations')->insert(['faq_id' => $id, 'language_id' => $lid, 'question' => $q, 'answer' => $a, 'created_at' => $now, 'updated_at' => $now]);
        }
        $faqs++;
    }

    DB::table('settings')->updateOrInsert(['variable' => 'lebazone_blog_faq_v1'], ['value' => '1']);
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    try { \Illuminate\Support\Facades\Artisan::call('config:cache'); } catch (\Throwable $e) {}
    echo "Blog & FAQ: $added blog post(s) and $faqs FAQ(s) added.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "  ! Blog & FAQ failed: " . $e->getMessage() . "\n");
    exit(1);
}
