<?php
// Lebazone: products without a photo show the Lebazone "no photo" image (was the SnapBuy logo).
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rel = 'front_end/placeholder_image/lebazone-no-photo-v2.png';
$dest = $base . '/storage/app/public/' . $rel;
@mkdir(dirname($dest), 0777, true);
copy(__DIR__ . '/brand-assets/brand/lebazone-no-photo.png', $dest);
DB::table('settings')->updateOrInsert(['variable' => 'placeholder_image'], ['value' => $rel]);
echo "No-photo image: Lebazone placeholder set.\n";
