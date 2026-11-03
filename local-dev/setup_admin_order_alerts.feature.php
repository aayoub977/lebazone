<?php
// Lebazone: make sure the admin panel gets an alert (bell notification) for new orders and
// order status changes. The stock install ships these switched off for the admin audience.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$n = DB::table('notification_admin_settings')
    ->where('audience', 'admin')->where('channel', 'push')
    ->where('event_key', 'like', 'order_status_%')
    ->where('is_enabled', 0)
    ->update(['is_enabled' => 1, 'updated_at' => now()]);
echo "Admin order alerts: switched on ($n event types updated).\n";
