<?php
// Lebazone: bring the "Orders Online" app's data (items, suppliers, shipments, freight rates, pictures)
// into Admin → Purchasing. Safe to run again: anything already imported (same source id) is skipped.
$dev = __DIR__;
$base = $dev . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

$file = "$dev/purchasing-import/orders-online.json";
if (!is_file($file) || !Schema::hasTable('pur_items')) { echo "Purchasing import: nothing to import.\n"; return; }
$d = json_decode(file_get_contents($file), true);
if (!$d) { echo "Purchasing import: the data file could not be read.\n"; return; }

$ts = function ($v) { if (!$v) return now(); try { return \Carbon\Carbon::parse($v)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'); } catch (\Throwable $e) { return now(); } };
$num = fn ($v) => ($v === null || $v === '') ? null : (float) $v;
$added = ['suppliers' => 0, 'shipments' => 0, 'rates' => 0, 'items' => 0, 'pictures' => 0];

DB::transaction(function () use ($d, $ts, $num, &$added, $dev) {
    $map = ['suppliers' => [], 'shipments' => [], 'rates' => []];
    $existing = fn ($t, $sid) => DB::table($t)->where('source_id', $sid)->value('id');

    foreach ($d['suppliers'] ?? [] as $s) {
        $id = $existing('pur_suppliers', $s['source_id']);
        if (!$id) {
            $row = array_intersect_key($s, array_flip(['name', 'company', 'platform', 'contact', 'phone', 'whatsapp', 'wechat', 'email', 'website', 'address', 'city', 'country', 'payment_terms', 'moq', 'bank', 'notes']));
            $row += ['lead_time' => $num($s['lead_time'] ?? null), 'rating' => !empty($s['rating']) ? (int) $s['rating'] : null, 'source_id' => $s['source_id'],
                'created_at' => $ts($s['created_at'] ?? null), 'updated_at' => $ts($s['updated_at'] ?? null)];
            $row['name'] = $row['name'] ?: 'Supplier';
            $id = DB::table('pur_suppliers')->insertGetId($row);
            $added['suppliers']++;
        }
        $map['suppliers'][$s['source_id']] = $id;
    }
    foreach ($d['rates'] ?? [] as $r) {
        $id = $existing('pur_rates', $r['source_id']);
        if (!$id) {
            $id = DB::table('pur_rates')->insertGetId(['name' => $r['name'], 'country' => $r['country'], 'mode' => $r['mode'], 'price' => (float) $r['price'], 'auto' => (int) $r['auto'],
                'notes' => $r['notes'], 'history' => json_encode($r['history'] ?? []), 'source_id' => $r['source_id'], 'created_at' => $ts($r['created_at'] ?? null), 'updated_at' => $ts($r['updated_at'] ?? null)]);
            $added['rates']++;
        }
        $map['rates'][$r['source_id']] = $id;
    }
    foreach ($d['shipments'] ?? [] as $s) {
        $id = $existing('pur_shipments', $s['source_id']);
        if (!$id) {
            $rateId = $s['rate'] ? ($map['rates'][$s['rate']] ?? null) : null;
            $id = DB::table('pur_shipments')->insertGetId(['name' => $s['name'] ?: 'Shipment', 'status' => $s['status'], 'ship_date' => $s['ship_date'] ?: null, 'eta' => $s['eta'] ?: null,
                'forwarder' => $s['forwarder'], 'tracking' => $s['tracking'], 'rate_mode' => $s['rate_mode'] === 'rate' && !$rateId ? '' : $s['rate_mode'], 'rate_id' => $rateId,
                'cbm_price' => $num($s['cbm_price']), 'delivery_cost' => (float) ($s['delivery_cost'] ?? 0), 'allocation' => $s['allocation'] ?: 'cbm', 'notes' => $s['notes'],
                'source_id' => $s['source_id'], 'created_at' => $ts($s['created_at'] ?? null), 'updated_at' => $ts($s['updated_at'] ?? null)]);
            $added['shipments']++;
        }
        $map['shipments'][$s['source_id']] = $id;
    }
    Storage::disk('public')->makeDirectory('purchasing');
    foreach ($d['items'] ?? [] as $it) {
        if ($existing('pur_items', $it['source_id'])) continue;
        $pic = null;
        if (!empty($it['picture']) && is_file("$dev/purchasing-import/images/{$it['picture']}")) {
            $pic = 'purchasing/oo-' . $it['picture'];
            copy("$dev/purchasing-import/images/{$it['picture']}", Storage::disk('public')->path($pic));
            $added['pictures']++;
        }
        $rateId = $it['rate'] ? ($map['rates'][$it['rate']] ?? null) : null;
        $row = [];
        foreach (['qty', 'boxes', 'per_box', 'box_l', 'box_w', 'box_h', 'item_l', 'item_w', 'item_h', 'unit_cost', 'sell_price', 'delivery', 'cbm_custom'] as $k) $row[$k] = $num($it[$k] ?? null);
        $row += ['name' => $it['name'], 'sku' => $it['sku'], 'variants' => $it['variants'], 'picture' => $pic,
            'supplier_id' => $it['supplier'] ? ($map['suppliers'][$it['supplier']] ?? null) : null,
            'shipment_id' => $it['shipment'] ? ($map['shipments'][$it['shipment']] ?? null) : null,
            'status' => $it['status'] ?: 'Quote', 'cbm_basis' => in_array($it['cbm_basis'], ['box', 'item'], true) ? $it['cbm_basis'] : '',
            'rate_mode' => $it['rate_mode'] === 'rate' && !$rateId ? '' : $it['rate_mode'], 'rate_id' => $rateId, 'notes' => $it['notes'],
            'source_id' => $it['source_id'], 'created_at' => $ts($it['created_at'] ?? null), 'updated_at' => $ts($it['updated_at'] ?? null)];
        if ($row['shipment_id']) $row['delivery'] = null;
        DB::table('pur_items')->insert($row);
        $added['items']++;
    }
    // settings: only the first time
    if (!DB::table('pur_settings')->where('key', 'imported_orders_online')->exists()) {
        foreach (['cbm_price', 'currency', 'carton_pad'] as $k) {
            if (isset($d['settings'][$k]) && $d['settings'][$k] !== null && $d['settings'][$k] !== '') {
                DB::table('pur_settings')->updateOrInsert(['key' => $k], ['value' => (string) $d['settings'][$k], 'updated_at' => now()]);
            }
        }
        DB::table('pur_settings')->insert(['key' => 'imported_orders_online', 'value' => now()->toDateTimeString(), 'created_at' => now(), 'updated_at' => now()]);
    }
});

echo "Purchasing import: {$added['items']} items, {$added['suppliers']} suppliers, {$added['shipments']} shipments, {$added['rates']} rates, {$added['pictures']} pictures added.\n";
