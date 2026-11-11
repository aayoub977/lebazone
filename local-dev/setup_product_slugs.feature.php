<?php
// Lebazone: once - products whose web address was made from their first colour's name
// (e.g. /product/white-medium) get an address from the PRODUCT's name instead
// (e.g. /product/home-creation-bamboo-led-mirror-cabinet). Flag lebazone_slugs_v1.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

if (DB::table('settings')->where('variable', 'lebazone_slugs_v1')->value('value') === '1') return;

try {
    $slugify = fn (string $s) => trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s)), '-');
    $done = 0;
    foreach (DB::table('products')->whereNull('deleted_at')->get(['id', 'name', 'slug']) as $p) {
        $name = trim((string) $p->name);
        if ($name === '') continue;
        $want = $slugify($name) ?: 'product';
        $slug = (string) $p->slug;
        if ($slug === $want || str_starts_with($slug, $want . '-')) continue; // already from the product name
        $vname = (string) DB::table('product_variants')->where('product_id', $p->id)->whereNull('deleted_at')->orderBy('id')->value('name');
        $vslug = $slugify($vname);
        if ($vslug === '' || !($slug === $vslug || str_starts_with($slug, $vslug . '-'))) continue; // custom address - leave it
        $new = $want; $i = 1;
        while (DB::table('products')->where('slug', $new)->where('id', '!=', $p->id)->exists()) { $i++; $new = "$want-$i"; }
        DB::table('products')->where('id', $p->id)->update(['slug' => $new, 'updated_at' => now()]);
        $done++;
    }
    DB::table('settings')->updateOrInsert(['variable' => 'lebazone_slugs_v1'], ['value' => '1']);
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    try { \Illuminate\Support\Facades\Artisan::call('config:cache'); } catch (\Throwable $e) {}
    echo "Product web addresses: $done renamed from colour names to product names.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "  ! Product slugs failed: " . $e->getMessage() . "\n");
    exit(1);
}
