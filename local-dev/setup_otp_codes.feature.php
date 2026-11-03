<?php
// Local-dev (Hader): sign-up / login codes go through Hader's own gateway
// (WhatsApp first, SMS fallback) instead of Firebase. Until the WhatsApp/SMS
// keys are entered, "test mode" writes each code to
// admin_panel_v1.3.1/storage/logs/otp-codes.log (see show-codes.bat).
// Test mode is ignored when APP_ENV=production.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

foreach ([
    'phone_login' => '1',
    'phone_auth_otp' => '1',
    'firebase_authentication' => '0',
    'custom_sms_gateway_otp_based' => '1',
    'otp_test_mode' => '1',
] as $k => $v) {
    DB::table('settings')->updateOrInsert(['variable' => $k], ['value' => $v]); // settings has no timestamps
}
echo "Sign-up codes: WhatsApp/SMS gateway (test mode: codes in show-codes.bat).\n";
