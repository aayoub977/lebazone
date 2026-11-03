<?php
// Lebazone: checks why sign-up emails don't arrive. Usage: php mail_check.php you@example.com
$base = __DIR__ . '/../../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Helpers\CommonHelper;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

$to = $argv[1] ?? '';
$g = fn ($k) => trim((string) Setting::get_value($k));
echo "== Email settings (admin > Settings > SMTP) ==\n";
foreach (['mailer', 'smtp_from_mail', 'smtp_host', 'smtp_port', 'smtp_encryption_type', 'smtp_content_type'] as $k) {
    printf("  %-22s %s\n", $k, $g($k) === '' ? '(EMPTY)' : $g($k));
}
$pw = $g('smtp_email_password');
printf("  %-22s %s\n", 'smtp_email_password', $pw === '' ? '(EMPTY)' : '(set, ' . strlen($pw) . ' characters' . (str_contains($pw, ' ') ? ' - CONTAINS SPACES, remove them' : '') . ')');

echo "\n== Login settings ==\n";
foreach (['email_login', 'phone_login', 'google_login'] as $k) printf("  %-22s %s\n", $k, $g($k));

echo "\n== Sign-up email template ==\n";
$t = DB::table('email_templates')->where('type', 'verify_email_customer')->first();
echo $t ? "  verify_email_customer: found\n" : "  verify_email_customer: MISSING - no code email can be sent\n";

if ($to === '') { echo "\n(no address given - nothing sent)\n"; exit; }
echo "\n== Sending a test code email to $to ==\n";
try {
    CommonHelper::sendMailByTemplate($to, 'verify_email_customer', ['customer_name' => 'Test', 'code' => '123456']);
    echo "  Sent without an error. Check the inbox AND the spam folder.\n";
} catch (\Throwable $e) {
    echo "  FAILED: " . $e->getMessage() . "\n";
}
$log = $base . '/storage/logs/laravel.log';
if (is_file($log)) {
    $lines = array_slice(file($log), -400);
    $hits = array_values(array_filter($lines, fn ($l) => stripos($l, 'mail') !== false || stripos($l, 'smtp') !== false));
    if ($hits) { echo "\n== Recent mail lines in the log ==\n"; foreach (array_slice($hits, -8) as $l) echo '  ' . substr(trim($l), 0, 300) . "\n"; }
}
