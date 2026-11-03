<?php
// Lebazone: use the downloaded photos (catalog\images\<sku>-1.jpg) as each product's main
// picture, and a matching product photo for each category. Safe to run again.
$dev = __DIR__;
$base = $dev . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$src = "$dev/catalog/images";
$dst = "$base/storage/app/public/catalog/photos";
@mkdir($dst, 0777, true);
$catPhoto = ['audio' => 'lbz-aud-002', 'wearables' => 'lbz-wea-001', 'power' => 'lbz-pwr-001', 'cameras' => 'lbz-cam-001',
             'gaming' => 'lbz-gam-001', 'smarthome' => 'lbz-smh-001', 'computer' => 'lbz-gam-003'];
$done = 0;
foreach (DB::table('product_variants')->where('sku', 'like', 'LBZ-%')->get() as $v) {
    $file = strtolower($v->sku) . '-1.jpg';
    if (!is_file("$src/$file") || filesize("$src/$file") < 5000) { echo "  ! no photo for {$v->sku}\n"; continue; }
    copy("$src/$file", "$dst/$file");
    $path = "catalog/photos/$file";
    DB::table('product_variants')->where('id', $v->id)->update(['image' => $path]);
    if (Schema::hasColumn('products', 'image')) DB::table('products')->where('id', $v->product_id)->update(['image' => $path]);
    $done++;
}
foreach ($catPhoto as $key => $sku) {
    $file = "$sku-1.jpg";
    if (!is_file("$dst/$file")) continue;
    DB::table('categories')->where('slug', "lebazone-$key")->update(['image' => "catalog/photos/$file"]);
}
\Illuminate\Support\Facades\Artisan::call('optimize:clear');
echo "Photos set on $done products and the categories.\n";
