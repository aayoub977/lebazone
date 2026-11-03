<?php
// Brand kit, step 1 (file edits only, no database): turns a fresh copy of the
// Hader project into a separate store. Run from <store>\local-dev by make-brand.bat.
// Idempotent: safe to run again.
$dev  = __DIR__;                              // <DEST>\local-dev
$root = dirname($dev);                        // <DEST>
$b    = json_decode(file_get_contents("$dev/brand.json"), true) or die("brand.json missing\n");
$K = $b['key']; $N = $b['name']; $NA = $b['name_ar'];
$assets = "$dev/brand-assets";
$admin = "$root/admin_panel_v1.3.1";
$web   = "$root/website_v.1.3.1/website_v.1.3.1";
$apps  = "$root/apps";
$isUAE = $b['country'] === 'AE';
$say = fn ($m) => print("  - $m\n");

function setEnv(string $file, array $vals): void {
    $s = file_exists($file) ? file_get_contents($file) : '';
    foreach ($vals as $k => $v) {
        $line = "$k=$v";
        if (preg_match('/^' . preg_quote($k, '/') . '=.*$/m', $s)) {
            $s = preg_replace('/^' . preg_quote($k, '/') . '=.*$/m', str_replace('$', '\$', $line), $s);
        } else {
            $s = rtrim($s) . "\n$line\n";
        }
    }
    file_put_contents($file, $s);
}
function replaceIn(string $file, array $map): void {
    if (!is_file($file)) return;
    $s = file_get_contents($file); $o = $s;
    $s = strtr($s, $map);
    if ($s !== $o) file_put_contents($file, $s);
}
function copyTo(string $src, string $dst): void {
    if (!is_file($src)) return;
    @mkdir(dirname($dst), 0777, true);
    copy($src, $dst);
}
function walk(string $dir, array $exts, callable $fn): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (in_array(strtolower($f->getExtension()), $exts, true)) $fn($f->getPathname());
    }
}

echo "Branding files for $N...\n";

// 1. Admin/API .env
setEnv("$admin/.env", [
    'APP_NAME' => $N,
    'APP_URL' => "http://127.0.0.1:{$b['api_port']}",
    'DB_DATABASE' => $b['db'],
    'SESSION_COOKIE' => "{$K}_session",   // keep logins separate from Hader on 127.0.0.1
]);
$say('admin .env (database, port, name)');

// 2. Website .env + colours + texts + icons
setEnv("$web/.env", [
    'NEXT_PUBLIC_API_URL' => "http://127.0.0.1:{$b['api_port']}",
    'NEXT_PUBLIC_WEB_NAME' => $N,
    'NEXT_PUBLIC_BASE_URL' => "http://localhost:{$b['web_port']}",
]);
replaceIn("$web/src/styles/globals.css", ['#0369A1' => $b['primary'], '#38BDF8' => $b['primary_dark_mode']]);
$textMap = ['Hader' => $N, 'حاضر' => $NA];
if ($isUAE) { $textMap['across Lebanon'] = 'across the UAE'; $textMap['in Lebanon'] = 'in the UAE'; }
walk("$web/src", ['ts', 'tsx', 'json'], function ($f) use ($textMap) {
    if (basename($f) === 'ur.json') return;  // Urdu uses the word حاضر normally
    replaceIn($f, $textMap);
});
copyTo("$assets/brand/favicon.webp", "$web/public/favicon.webp");
copyTo("$assets/brand/apple-touch-icon.png", "$web/public/icons/apple-touch-icon.png");
copyTo("$assets/brand/$K-icon-192.png", "$web/public/icons/icon-192.png");
copyTo("$assets/brand/$K-pwa-512.png", "$web/public/icons/icon-512.png");
$say('website (.env, colours, texts, icons)');

// 3. Admin images, logos in storage, app language files
$st = "$admin/storage/app/public";
copyTo("$assets/brand/$K-logo.png", "$st/logo/$K-logo.png");
copyTo("$assets/brand/$K-logo.png", "$st/front_end/web_logo/$K-logo.png");
copyTo("$assets/brand/$K-favicon-64.png", "$st/admin_favicon/$K-favicon.png");
copyTo("$assets/brand/$K-favicon-64.png", "$st/front_end/favicon/$K-favicon.png");
copyTo("$assets/brand/$K-pwa-512.png", "$st/front_end/pwa_icon/$K-pwa-512.png");
copyTo("$assets/brand/$K-logo.png", "$admin/public/images/logo.png");
copyTo("$assets/brand/$K-logo.png", "$admin/public/images/admin_logo.png");
copyTo("$assets/brand/$K-favicon-64.png", "$admin/public/images/favicon.png");
foreach (glob("$assets/images/*.png") as $img) copyTo($img, "$st/catalog/" . basename($img));
walk("$admin/resources/lang/apps", ['json'], fn ($f) => replaceIn($f, $textMap));
foreach (array_merge(glob("$admin/resources/views/*.blade.php") ?: [], glob("$admin/resources/views/layouts/*.blade.php") ?: []) as $f) replaceIn($f, ["'Hader'" => "'$N'", '"Hader"' => "\"$N\""]);
replaceIn("$admin/resources/js/views/Sellers/Sellers.vue", ['Hader' => $N]);
$say('admin logos, catalogue pictures, app texts');

// 4. local-dev scripts
$P = ['api' => $b['api_port'], 'web' => $b['web_port']];
replaceIn("$dev/start-all.bat", [
    'Hader' => $N, 'port=8000' => "port={$P['api']}", '(port 8000)' => "(port {$P['api']})", '(port 3000)' => "(port {$P['web']})",
    'http://127.0.0.1:8000' => "http://127.0.0.1:{$P['api']}", 'http://localhost:3000' => "http://localhost:{$P['web']}",
    'cmd /k "npm run dev"' => "cmd /k \"npm run dev -- -p {$P['web']}\"",
]);
foreach (['apply-updates.bat', 'show-codes.bat', 'check-app.bat', 'setup-app-tools.bat'] as $f) replaceIn("$dev/$f", ['Hader' => $N]);
$appsWin = str_replace('/', '\\', realpath($apps) ?: $apps);
foreach (['run-customer-app.bat' => 'customer', 'run-driver-app.bat' => 'driver', 'check-app.bat' => 'customer'] as $f => $a) {
    replaceIn("$dev/$f", ['Hader' => $N, "C:\\laragon\\www\\hader-apps\\$a" => "$appsWin\\$a", 'port 8000' => "port {$P['api']}"]);
}
$off = ['add_demo_beirut.php', 'add-demo-beirut.bat', 'setup_test_customer.feature.php', 'setup_home_colors.feature.php'];
$off = array_merge($off, $isUAE ? ['setup_lebanon.php', 'setup_whish_demo.feature.php'] : ['setup_uae.feature.php']);
foreach ($off as $f) if (is_file("$dev/$f")) rename("$dev/$f", "$dev/$f.off");
copy("$dev/brand_set_branding.php", "$dev/set_branding.php");
file_put_contents("$dev/LOCAL_LOGINS.txt", "$N - local logins\n(admin login is the same as Hader's; see Hader's LOCAL_LOGINS.txt)\n\nAdmin + API:  http://127.0.0.1:{$P['api']}\nWebsite:      http://localhost:{$P['web']}\nStore panel:  {$b['store']['email']} / {$b['store']['password']}\n");
$say('local-dev scripts (ports, names, store-specific setup)');

// 5. Apps (customer + driver)
foreach (['customer' => [$b['app_id'], $N, 'customer'], 'driver' => [$b['driver_id'], "$N Driver", 'delivery_boy']] as $a => [$id, $name, $prefix]) {
    $d = "$apps/$a";
    if (!is_dir($d)) continue;
    $env = json_decode(file_get_contents("$d/env/dev.json"), true);
    $env['APPLICATION_ID'] = $id; $env['APP_NAME'] = $name;
    $env['BASE_URL'] = "http://10.0.2.2:{$b['api_port']}/$prefix/";
    if (isset($env['WEB_URL'])) $env['WEB_URL'] = "http://10.0.2.2:{$b['web_port']}";
    if (isset($env['DEEPLINK_SCHEME'])) { $env['DEEPLINK_SCHEME'] = $K; $env['DEEPLINK_HOST'] = "$K.local"; }
    file_put_contents("$d/env/dev.json", json_encode($env, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $hex = strtoupper(ltrim($b['primary'], '#'));
    replaceIn("$d/lib/core/theme/app_colors.dart", [
        'Color(0xFF0369A1)' => "Color(0xFF$hex)", 'Color(0xFF075985)' => "Color(0xFF$hex)",
        'Color(0xFF0284C7)' => "Color(0xFF$hex)", 'Color(0xFFE0F2FE)' => 'Color(0xFFF3F4F6)',
    ]);
    replaceIn("$d/pubspec.yaml", ['adaptive_icon_background: "#0369A1"' => "adaptive_icon_background: \"{$b['tile_bg']}\"", 'Hader' => $N]);
    replaceIn("$d/android/app/src/main/res/values/colors.xml", ['#0369A1' => $b['tile_bg']]);
    copyTo("$assets/brand/app_icon_512.png", "$d/assets/logo/app_icon.png");
    copyTo("$assets/brand/app_icon_foreground_512.png", "$d/assets/logo/app_icon_foreground.png");
    copyTo("$assets/brand/$K-glyph.svg", "$d/assets/images/svg/logo/splase_logo.svg");
    copyTo("$assets/brand/$K-placeholder.svg", "$d/assets/images/svg/logo/place_holder.svg");
    replaceIn("$d/assets/languages/en.json", ['"Hader"' => "\"$N\"", '"Hader Driver"' => "\"$N Driver\""]);
    if ($isUAE) {
        replaceIn("$d/lib/core/configs/app_config.dart", ["code: 'LB'," => "code: 'AE',", "dialCode: '+961'," => "dialCode: '+971',"]);
    }
}
// Firebase: use local-dev\firebase\google-services.json once it lists this store's apps.
$gs = "$dev/firebase/google-services.json";
$gsj = is_file($gs) ? json_decode(file_get_contents($gs), true) : null;
$byPkg = [];
foreach (($gsj['client'] ?? []) as $c) $byPkg[$c['client_info']['android_client_info']['package_name']] = $c;
foreach (['customer' => $b['app_id'], 'driver' => $b['driver_id']] as $a => $pkg) {
    $d = "$apps/$a";
    if (!is_file("$d/android/app/google-services.json") && !isset($byPkg[$pkg])) continue;
    if (!isset($byPkg[$pkg])) {
        // Not added in Firebase yet: borrow Hader's entry under the new package name so the
        // app still builds and runs. Notifications/phone login via Firebase need the real one.
        $j = json_decode(file_get_contents("$d/android/app/google-services.json"), true);
        $src = null;
        foreach (($j['client'] ?? []) as $c) {
            $p = $c['client_info']['android_client_info']['package_name'];
            if ($p === $pkg) { $src = null; break; }
            if ($src === null || $p === ($a === 'driver' ? 'com.hader.driver' : 'com.hader.app')) $src = $c;
        }
        if ($src) {
            $src['client_info']['android_client_info']['package_name'] = $pkg;
            $j['client'][] = $src;
            file_put_contents("$d/android/app/google-services.json", json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        echo "  ! Firebase: $pkg isn't in your Firebase project yet - using a temporary stand-in (see the guide)\n";
        continue;
    }
    copy($gs, "$d/android/app/google-services.json");
    $c = $byPkg[$pkg]; $pi = $gsj['project_info'];
    $opt = "$d/lib/firebase_options.dart"; $s = file_get_contents($opt);
    $s = preg_replace('/static const FirebaseOptions android = FirebaseOptions\([^;]*\);/s',
        "static const FirebaseOptions android = FirebaseOptions(\n    apiKey: '{$c['api_key'][0]['current_key']}',\n    appId: '{$c['client_info']['mobilesdk_app_id']}',\n    messagingSenderId: '{$pi['project_number']}',\n    projectId: '{$pi['project_id']}',\n    storageBucket: '{$pi['storage_bucket']}',\n  );", $s, 1);
    file_put_contents($opt, $s);
    echo "  - Firebase connected for $pkg\n";
}
$say('apps (ids, names, colours, icons, server address)');
echo "Done.\n";
