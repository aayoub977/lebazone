<?php
// Lebazone: once, on the LIVE shop (mini PC) only - security hardening (2026-11-12).
//   1. Runs the shop in "production" mode (APP_ENV=production) instead of "local".
//   2. Windows firewall: the database port (3306) can't be reached from other devices on
//      the network (the shop itself connects from this PC, which is not affected).
//   3. Demo logins created by the setup get new random passwords:
//        - the "test customer" (+961 70000000) is switched off,
//        - the store panel login (store@lebazone.local) gets a random password,
//      written to local-dev\LOCAL_LOGINS.txt on this PC (never sent to GitHub).
// Flag security_v1.
$base = __DIR__ . '/../admin_panel_v1.3.1';
if (!is_file(__DIR__ . '/server/logs/update.log')) return; // the dev PC never runs this

require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::table('settings')->where('variable', 'security_v1')->value('value') === '1') return;

$done = [];
try {
    // 1. production mode
    $envFile = $base . '/.env';
    $env = (string) @file_get_contents($envFile);
    if ($env !== '') {
        $env = preg_match('/^APP_ENV=.*$/m', $env) ? preg_replace('/^APP_ENV=.*$/m', 'APP_ENV=production', $env) : rtrim($env) . "\nAPP_ENV=production\n";
        $env = preg_match('/^APP_DEBUG=.*$/m', $env) ? preg_replace('/^APP_DEBUG=.*$/m', 'APP_DEBUG=false', $env) : rtrim($env) . "\nAPP_DEBUG=false\n";
        file_put_contents($envFile, $env);
        $done[] = 'production mode';
    }

    // 2. firewall (Windows; needs the update task's admin rights)
    if (stripos(PHP_OS, 'WIN') === 0) {
        foreach (['3306' => 'database'] as $port => $what) {
            $name = "Lebazone - block $what port $port from the network";
            exec('netsh advfirewall firewall delete rule name="' . $name . '" >nul 2>&1');
            exec('netsh advfirewall firewall add rule name="' . $name . '" dir=in action=block protocol=TCP localport=' . $port . ' 2>&1', $o, $code);
            if ($code === 0) $done[] = "firewall $port";
            else fwrite(STDERR, "  ! firewall rule for port $port not added (code $code)\n");
        }
    }

    // 3. demo logins
    $lines = [];
    $tc = DB::table('users')->where('mobile', '70000000')->whereIn('country_code', ['+961', '961'])->first();
    if ($tc) {
        DB::table('users')->where('id', $tc->id)->update([
            'password' => bcrypt(bin2hex(random_bytes(16))),
            'status' => 0,
            'updated_at' => now(),
        ]);
        $done[] = 'test customer off';
    }
    $storeEmail = 'store@lebazone.local';
    if (Schema::hasTable('admins') && DB::table('admins')->where('email', $storeEmail)->exists()) {
        $pw = 'Lbz-' . bin2hex(random_bytes(6));
        DB::table('admins')->where('email', $storeEmail)->update(['password' => bcrypt($pw), 'updated_at' => now()]);
        $lines[] = "Store panel ($storeEmail) new password: $pw   (changed " . date('Y-m-d') . ")";
        $done[] = 'store login password';
    }
    if ($lines) @file_put_contents(__DIR__ . '/LOCAL_LOGINS.txt', "\n" . implode("\n", $lines) . "\n", FILE_APPEND);

    DB::table('settings')->updateOrInsert(['variable' => 'security_v1'], ['value' => '1']);
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    try { \Illuminate\Support\Facades\Artisan::call('config:cache'); } catch (\Throwable $e) {}
    echo "Security: " . implode(', ', $done) . ".\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "  ! Security hardening failed: " . $e->getMessage() . "\n");
    exit(1);
}
