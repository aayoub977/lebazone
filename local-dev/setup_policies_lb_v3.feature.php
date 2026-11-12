<?php
// Lebazone: once - the Terms & Conditions and Return Policy were not filled the first time
// (their boxes held some default text, so they were skipped). This fills those two, unless
// you have already written a real policy there (more than 300 characters). Flag lebazone_policies_v3.
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LanguageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::table('settings')->where('variable', 'lebazone_policies_v3')->value('value') === '1') return;

$p = require __DIR__ . '/policies_lb_texts.php';
$fields = ['terms_conditions', 'return_policy'];
$isDraftOnly = fn ($v) => mb_strlen(trim(strip_tags((string) $v))) < 300 && stripos((string) $v, 'Last updated') === false;

$done = [];
$c = DB::table('countries')->whereRaw('UPPER(code) = ?', ['LB'])->first();
if (!$c) { echo "Policies v3: Lebanon missing - skipped.\n"; return; }

$upd = [];
foreach ($fields as $f) if (property_exists($c, $f) && $isDraftOnly($c->$f)) { $upd[$f] = $p[$f]; $done[$f] = 1; }
if ($upd) DB::table('countries')->where('id', $c->id)->update($upd);

if (Schema::hasTable('country_translations')) {
    $lang = app(LanguageService::class)->getDefaultLanguage();
    $lid = $lang ? (int) $lang->id : 0;
    foreach (DB::table('country_translations')->where('country_id', $c->id)->get() as $row) {
        // the default language gets the text; other languages: only clear the leftover default text
        $tu = [];
        foreach ($fields as $f) {
            if (!property_exists($row, $f) || !$isDraftOnly($row->$f)) continue;
            $tu[$f] = ((int) $row->language_id === $lid) ? $p[$f] : null;
            $done[$f] = 1;
        }
        if ($tu) DB::table('country_translations')->where('id', $row->id)->update($tu + ['updated_at' => now()]);
    }
}

DB::table('settings')->updateOrInsert(['variable' => 'lebazone_policies_v3'], ['value' => '1']);
\Illuminate\Support\Facades\Artisan::call('optimize:clear');
try { \Illuminate\Support\Facades\Artisan::call('config:cache'); } catch (\Throwable $e) {}
echo 'Policies v3: filled ' . implode(', ', array_keys($done)) . ".\n";
