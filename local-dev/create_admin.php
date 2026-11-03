<?php
// Local-dev only: creates the super admin (same as the vendor web installer does).
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = $argv[1];
$password = trim(file_get_contents(__DIR__ . '/.adminpw'));

App\Models\Admin::truncate();
$admin = App\Models\Admin::create([
    'username'   => 'superadmin',
    'email'      => $email,
    'password'   => bcrypt($password),
    'role_id'    => 1,
    'created_by' => 1,
]);
$admin->assignRole('Super Admin');
file_put_contents($base . '/storage/installed', "SnapBuy installed locally on " . date('Y/m/d h:i:sa') . "\n");
echo "Super admin created: $email\n";
