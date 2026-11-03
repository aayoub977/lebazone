<?php
// Lebazone: start fresh for a full test. A complete database backup is written FIRST
// (local-dev/backups/...sql); if the backup fails, nothing is deleted.
// Wipes: orders & payments, customers, products, employees & drivers.
// Keeps: settings & branding, categories, brands, colours, countries/zones/regions, payment setup,
//        roles & permissions, home page layout, and the super admin (email -> admin@lebazone.store).
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$mysqldump = $argv[1] ?? 'mysqldump';
$c = config('database.connections.' . config('database.default'));

// ---- 1. backup
@mkdir(__DIR__ . '/backups', 0777, true);
$file = __DIR__ . '/backups/lebazone-before-reset-' . date('Y-m-d_His') . '.sql';
$cmd = '"' . $mysqldump . '" --single-transaction --routines --default-character-set=utf8mb4'
    . ' -h ' . escapeshellarg($c['host']) . ' -P ' . escapeshellarg((string) $c['port'])
    . ' -u ' . escapeshellarg($c['username']) . ((string) ($c['password'] ?? '') !== '' ? ' --password=' . escapeshellarg($c['password']) : '')
    . ' --result-file=' . escapeshellarg($file) . ' ' . escapeshellarg($c['database']) . ' 2>&1';
exec($cmd, $o, $code);
if ($code !== 0 || !is_file($file) || filesize($file) < 10000) {
    echo "BACKUP FAILED - nothing was deleted.\n" . implode("\n", $o) . "\n";
    exit(1);
}
echo "Backup saved: " . basename($file) . " (" . round(filesize($file) / 1048576, 1) . " MB)\n";

// ---- 2. wipe
$groups = [
    'Orders & payments' => ['orders', 'order_items', 'order_item_taxes', 'order_statuses', 'order_fulfillments', 'order_fulfillment_logs',
        'print_jobs', 'transactions', 'return_requests', 'return_request_statuses', 'carts', 'cart_notifications', 'local_payment_sessions',
        'live_tracking', 'loyalty_point_transactions', 'spin_wheel_spins', 'seller_ledger', 'withdrawal_requests', 'conversations', 'messages',
        'notifications', 'panel_notifications', 'delivery_boy_notifications', 'jobs', 'failed_jobs', 'emails', 'activity_logs',
        'api_call_tracking', 'app_usages', 'sessions'],
    'Customers' => ['users', 'user_addresses', 'user_tokens', 'user_wallets', 'wallet_transactions', 'favorites', 'recently_visited_products',
        'product_ratings', 'rating_images', 'sms_verifications', 'password_resets', 'personal_access_tokens', 'oauth_access_tokens',
        'oauth_refresh_tokens', 'oauth_auth_codes'],
    'Products' => ['products', 'product_translations', 'product_variants', 'product_variant_translations', 'product_variant_store_stocks',
        'product_images', 'product_variant_attribute_values', 'product_variant_custom_values', 'product_variant_custom_value_translations',
        'product_stock_alerts', 'product_recommendations', 'product_bulk_imports', 'flash_sale_items', 'flash_sale_price_backups'],
    'Employees & drivers' => ['warehouse_staff', 'delivery_boys', 'delivery_boy_translations', 'delivery_boy_cash_collections',
        'delivery_boy_settlements', 'delivery_boy_salaries'],
];
DB::statement('SET FOREIGN_KEY_CHECKS=0');
foreach ($groups as $label => $tables) {
    $n = 0;
    foreach ($tables as $t) {
        if (Schema::hasTable($t)) { DB::table($t)->truncate(); $n++; }
    }
    echo "Cleared $label ($n tables)\n";
}

// Employee/driver logins: keep the super admin (and any store-owner login), remove the rest.
$super = DB::table('admins')->where('role_id', 1)->orderBy('id')->first();
if (!$super) { DB::statement('SET FOREIGN_KEY_CHECKS=1'); echo "No super admin found!\n"; exit(1); }
$removed = DB::table('admins')->where('id', '!=', $super->id)->whereNull('store_id')->delete();
$keep = DB::table('admins')->pluck('id');
if (Schema::hasTable('admin_passkeys')) DB::table('admin_passkeys')->whereNotIn('admin_id', $keep)->delete();
if (Schema::hasTable('admin_tokens')) DB::table('admin_tokens')->whereNotIn('user_id', $keep)->delete();
if (Schema::hasTable('model_has_roles')) DB::table('model_has_roles')->whereNotIn('model_id', $keep)->delete();
echo "Removed $removed employee/driver login(s); kept the super admin.\n";

// Super admin email
$old = $super->email;
DB::table('admins')->where('id', $super->id)->update(['email' => 'admin@lebazone.store', 'updated_at' => now()]);
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo "Super admin email: $old -> admin@lebazone.store (password unchanged)\n";

\Illuminate\Support\Facades\Artisan::call('optimize:clear');
echo "\nDone. Everyone (you too) must log in again.\n";
