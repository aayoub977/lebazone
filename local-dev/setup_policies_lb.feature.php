<?php
// Lebazone: once - writes the 5 customer policies for Lebanon (privacy, returns, shipping,
// cancellation, terms). Only fields that are still EMPTY or hold the placeholder text are
// filled - anything you already wrote is kept. Edit them any time in
// admin -> Countries -> Lebanon -> policies. Flag lebazone_policies_v1.
// (Drafts written 2026-11-12 - have them checked by a lawyer in Lebanon when you can.)
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LanguageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::table('settings')->where('variable', 'lebazone_policies_v1')->value('value') === '1') return;

$p = require __DIR__ . '/policies_lb_texts.php';

$c = DB::table('countries')->whereRaw('UPPER(code) = ?', ['LB'])->first();
if (!$c) { echo "Policies: Lebanon missing - skipped.\n"; return; }

$isPlaceholder = function ($v, $field) {
    $t = trim(strip_tags((string) $v));
    return $t === '' || strcasecmp($t, ucwords(str_replace('_', ' ', $field))) === 0
        || strcasecmp(str_replace(['&', ' and '], ['', ''], $t), str_replace(['&', ' and '], ['', ''], ucwords(str_replace('_', ' ', $field)))) === 0;
};

$filled = [];
$upd = [];
foreach ($p as $field => $html) {
    if (property_exists($c, $field) && $isPlaceholder($c->$field, $field)) { $upd[$field] = $html; $filled[$field] = 1; }
}
if ($upd) DB::table('countries')->where('id', $c->id)->update($upd);

// the policy pages read the language copy first - fill the default language too
if (Schema::hasTable('country_translations')) {
    $lang = app(LanguageService::class)->getDefaultLanguage();
    $lid = $lang ? (int) $lang->id : 0;
    if ($lid) {
        $row = DB::table('country_translations')->where('country_id', $c->id)->where('language_id', $lid)->first();
        $tu = [];
        foreach ($p as $field => $html) {
            if (!Schema::hasColumn('country_translations', $field)) continue;
            if (!$row || $isPlaceholder($row->$field ?? '', $field)) { $tu[$field] = $html; $filled[$field] = 1; }
        }
        if ($tu) {
            if ($row) DB::table('country_translations')->where('id', $row->id)->update($tu + ['updated_at' => now()]);
            else DB::table('country_translations')->insert($tu + ['country_id' => $c->id, 'language_id' => $lid, 'name' => $c->name ?? 'Lebanon', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}

DB::table('settings')->updateOrInsert(['variable' => 'lebazone_policies_v1'], ['value' => '1']);
\Illuminate\Support\Facades\Artisan::call('optimize:clear');
try { \Illuminate\Support\Facades\Artisan::call('config:cache'); } catch (\Throwable $e) {}
echo 'Policies: filled ' . count($filled) . ' (' . implode(', ', array_keys($filled)) . ') - fields you had already written were kept.' . "\n";
