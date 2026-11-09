<?php
// Lebazone: once - products of sellers that are NOT active (suspended / pending / rejected) come off
// the shop, marked 'seller_off' so they return by themselves when the seller is active again.
// (Before this, suspending a seller left their products on sale.) Flag: seller_off_v1.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (!Schema::hasTable('sellers') || !Schema::hasColumn('products', 'seller_review')) return;
if (DB::table('settings')->where('variable', 'seller_off_v1')->value('value') === '1') return;
$off = DB::table('sellers')->where('status', '!=', 'active')->pluck('id');
$n = $off->isEmpty() ? 0 : DB::table('products')->whereIn('seller_id', $off)->where('status', 1)->whereNull('seller_review')
    ->update(['status' => 0, 'seller_review' => 'seller_off']);
DB::table('settings')->updateOrInsert(['variable' => 'seller_off_v1'], ['value' => '1']);
echo "Sellers: $n product(s) of inactive sellers taken off the shop.\n";
