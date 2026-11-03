<?php
// Lebazone: once - sign-up / login by EMAIL only (with the 6-digit email code).
// Phone login and phone codes are switched off until an SMS / WhatsApp gateway is chosen.
// Google login is left as it is. Flag: email_only_login_v1 (switch phone back on in
// admin > Settings > Login settings whenever you like - this will not undo it).
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

if (DB::table('settings')->where('variable', 'email_only_login_v1')->value('value') === '1') return;

foreach ([
    'email_login' => '1',
    'phone_login' => '0',
    'phone_auth_otp' => '0',
    'phone_auth_password' => '0',
    'custom_sms_gateway_otp_based' => '0',
    'firebase_authentication' => '0',
    'otp_test_mode' => '0',
    'email_only_login_v1' => '1',
] as $k => $v) {
    DB::table('settings')->updateOrInsert(['variable' => $k], ['value' => $v]); // settings has no timestamps
}
try { \Illuminate\Support\Facades\Artisan::call('optimize:clear'); } catch (\Throwable $e) {}
echo "Login: email only (phone login and phone codes switched off).\n";
