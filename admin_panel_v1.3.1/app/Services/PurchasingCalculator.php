<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Custom (Lebazone): Purchasing cost engine — same rules as the Orders Online app.
 *
 *  Total pieces   = order quantity, or pieces per box × boxes (blank boxes = 1)
 *  Total CBM      = box L×W×H (cm) ÷ 1,000,000 × boxes   — or the estimated carton from the item size
 *  Goods          = cost per piece × total pieces
 *  Freight        = total CBM × CBM price (item rate → shipment rate → origin-country rate → default)
 *  Delivery       = one amount per shipment, split across its items (by CBM / pieces / value / equally)
 *  Total cost     = goods + freight + delivery;  cost per piece = total ÷ pieces
 */
class PurchasingCalculator
{
    public const MAX_CARTON_SIDE = 80;
    public const ALLOC = ['cbm' => 'by CBM (space used)', 'items' => 'by number of items', 'value' => 'by item cost', 'equal' => 'equally per item line'];

    public array $settings = [];
    public array $suppliers = [];
    public array $shipments = [];
    public array $rates = [];
    public array $items = [];
    private array $estCache = [];

    public function __construct(bool $load = true)
    {
        if ($load) $this->load();
    }

    public function load(): void
    {
        $this->settings = DB::table('pur_settings')->pluck('value', 'key')->all();
        $key = fn ($rows) => collect($rows)->map(fn ($r) => (array) $r)->keyBy('id')->all();
        $this->suppliers = $key(DB::table('pur_suppliers')->get());
        $this->shipments = $key(DB::table('pur_shipments')->get());
        $this->rates = $key(DB::table('pur_rates')->get());
        $this->items = $key(DB::table('pur_items')->get());
    }

    public static function num($v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_int($v) || is_float($v)) return (float) $v;
        $s = str_replace(',', '', trim((string) $v));
        return is_numeric($s) ? (float) $s : null;
    }

    public function setting(string $k, $default = null)
    {
        return $this->settings[$k] ?? $default;
    }

    public function cartonPad(): float
    {
        $p = self::num($this->setting('carton_pad'));
        return $p === null ? 3.0 : $p;
    }

    public function defaultRate(): float
    {
        return self::num($this->setting('cbm_price')) ?? 0.0;
    }

    /* ---------------- carton estimate ---------------- */

    private const ORIENTS = [[0, 1, 2], [0, 2, 1], [1, 0, 2], [1, 2, 0], [2, 0, 1], [2, 1, 0]];

    /** Pack n pieces in a grid (best orientation) + padding per side; prefer ≤80 cm sides, then the smallest, most cube-like carton. */
    public function estimateCarton(float $iL, float $iW, float $iH, float $n, float $pad): ?array
    {
        if (!($iL > 0 && $iW > 0 && $iH > 0 && $n > 0)) return null;
        $n = (int) min(ceil($n), 20000);
        $key = "$iL|$iW|$iH|$n|$pad";
        if (array_key_exists($key, $this->estCache)) return $this->estCache[$key];
        $d = [$iL, $iW, $iH];
        $best = null;
        foreach (self::ORIENTS as $o) {
            [$a, $b, $c] = [$d[$o[0]], $d[$o[1]], $d[$o[2]]];
            for ($nx = 1; $nx <= $n; $nx++) {
                for ($ny = 1; $nx * $ny <= $n; $ny++) {
                    $nz = (int) ceil($n / ($nx * $ny));
                    if ($nx * $ny * ($nz - 1) >= $n) continue;
                    $L = $nx * $a + $pad; $W = $ny * $b + $pad; $H = $nz * $c + $pad;
                    $over = max($L, $W, $H) > self::MAX_CARTON_SIDE ? 1 : 0;
                    $vol = $L * $W * $H;
                    $score = $vol + 2 * ($L * $W + $L * $H + $W * $H) * 0.02;
                    if (!$best || $over < $best['over'] || ($over === $best['over'] && $score < $best['score'])) {
                        $best = compact('L', 'W', 'H', 'vol', 'nx', 'ny', 'nz', 'over', 'score');
                    }
                }
            }
        }
        $out = $best ? [
            'L' => round($best['L'], 1), 'W' => round($best['W'], 1), 'H' => round($best['H'], 1),
            'cbm' => $best['vol'] / 1e6, 'layout' => "{$best['nx']} × {$best['ny']} × {$best['nz']}", 'too_big' => $best['over'] === 1,
        ] : null;
        return $this->estCache[$key] = $out;
    }

    public function fitCount($bL, $bW, $bH, $iL, $iW, $iH, float $pad): ?int
    {
        if (!($bL > 0 && $bW > 0 && $bH > 0 && $iL > 0 && $iW > 0 && $iH > 0)) return null;
        $in = [$bL - $pad, $bW - $pad, $bH - $pad];
        if (min($in) <= 0) return 0;
        $d = [$iL, $iW, $iH];
        $max = 0;
        foreach (self::ORIENTS as $o) {
            $max = max($max, (int) (floor($in[0] / $d[$o[0]]) * floor($in[1] / $d[$o[1]]) * floor($in[2] / $d[$o[2]])));
        }
        return $max;
    }

    /** Most pieces that fit one carton with no side over 80 cm (searched up to 500 or the quantity). */
    public function autoPerCarton(float $iL, float $iW, float $iH, float $q, float $pad): int
    {
        $ok = function (int $n) use ($iL, $iW, $iH, $pad) { $e = $this->estimateCarton($iL, $iW, $iH, $n, $pad); return $e && !$e['too_big']; };
        $lo = 1; $hi = max(1, (int) min(ceil($q), 500));
        if (!$ok(1)) return 1;
        while ($lo < $hi) { $mid = (int) ceil(($lo + $hi) / 2); if ($ok($mid)) $lo = $mid; else $hi = $mid - 1; }
        return $lo;
    }

    /* ---------------- freight rates ---------------- */

    public function rateLabel(?array $r): string
    {
        if (!$r) return '';
        $n = trim((string) ($r['name'] ?? ''));
        if ($n !== '') return $n;
        $parts = array_filter([trim((string) ($r['country'] ?? '')), trim((string) ($r['mode'] ?? ''))]);
        return $parts ? implode(' · ', $parts) : 'Saved rate';
    }

    public function countryRate(?string $country): ?array
    {
        $c = mb_strtolower(trim((string) $country));
        if ($c === '') return null;
        $list = array_values(array_filter($this->rates, fn ($r) => mb_strtolower(trim((string) $r['country'])) === $c));
        if (!$list) return null;
        foreach ($list as $r) if ((int) $r['auto'] === 1) return $r;
        usort($list, fn ($a, $b) => strcmp((string) $b['updated_at'], (string) $a['updated_at']));
        return $list[0];
    }

    public function shipRate(?array $sh): ?array
    {
        if (!$sh) return null;
        if (($sh['rate_mode'] ?? '') === 'rate' && isset($this->rates[$sh['rate_id']])) {
            $r = $this->rates[$sh['rate_id']];
            return ['rate' => (float) $r['price'], 'src' => $this->rateLabel($r) . ' (shipment)'];
        }
        if (($sh['rate_mode'] ?? '') === 'custom' && self::num($sh['cbm_price']) !== null) {
            return ['rate' => self::num($sh['cbm_price']), 'src' => 'Shipment price'];
        }
        return null;
    }

    public function resolveRate(array $it, ?array $sh): array
    {
        $mode = $it['rate_mode'] ?? '';
        if ($mode === 'custom' && self::num($it['cbm_custom'] ?? null) !== null) return ['rate' => self::num($it['cbm_custom']), 'src' => 'Custom for this item'];
        if ($mode === 'rate' && isset($this->rates[$it['rate_id'] ?? 0])) {
            $r = $this->rates[$it['rate_id']];
            return ['rate' => (float) $r['price'], 'src' => $this->rateLabel($r)];
        }
        if ($s = $this->shipRate($sh)) return $s;
        $sup = $this->suppliers[$it['supplier_id'] ?? 0] ?? null;
        if ($sup && ($cr = $this->countryRate($sup['country'] ?? null))) {
            return ['rate' => (float) $cr['price'], 'src' => $this->rateLabel($cr) . ' (auto: ' . $sup['country'] . ')'];
        }
        return ['rate' => $this->defaultRate(), 'src' => 'Default'];
    }

    public function originOf(array $it): string
    {
        $s = $this->suppliers[$it['supplier_id'] ?? 0] ?? null;
        return $s ? trim((string) ($s['country'] ?? '')) : '';
    }

    /* ---------------- the calculation ---------------- */

    /** @param array|null $items keyed by id (defaults to all saved items) */
    public function computeAll(?array $items = null): array
    {
        $items = $items ?? $this->items;
        $pad = $this->cartonPad();
        $defMargin = self::num($this->setting('default_margin')) ?? 35;
        $R = [];
        foreach ($items as $id => $it) {
            $n = fn ($k) => self::num($it[$k] ?? null);
            [$L, $W, $H] = [$n('box_l'), $n('box_w'), $n('box_h')];
            [$iL, $iW, $iH] = [$n('item_l'), $n('item_w'), $n('item_h')];
            $boxes = $n('boxes'); $perIn = $n('per_box'); $unit = $n('unit_cost'); $q = $n('qty');
            $pieceVol = ($iL > 0 && $iW > 0 && $iH > 0) ? $iL * $iW * $iH / 1e6 : null;
            $hasBox = $L !== null && $W !== null && $H !== null;

            $per = $perIn; $autoPer = false;
            if (!($per > 0) && $q > 0 && $pieceVol !== null) { $per = (float) $this->autoPerCarton($iL, $iW, $iH, $q, $pad); $autoPer = true; }
            $boxesDerived = false;
            if ($q > 0 && $per > 0) { $bx = $q / $per; $boxesDerived = true; } else { $bx = $boxes ?? 1.0; }
            $totalItems = $q > 0 ? $q : ($per > 0 ? $per * $bx : null);
            $qty = $totalItems ?? 1.0;

            $est = ($pieceVol !== null && $per > 0) ? $this->estimateCarton($iL, $iW, $iH, $per, $pad) : null;
            $basis = (string) ($it['cbm_basis'] ?? '');
            $useEst = $est && ($basis === 'item' || (!$hasBox && $basis !== 'box'));
            $realVol = $hasBox ? $L * $W * $H / 1e6 : null;
            $boxVol = $useEst ? $est['cbm'] : $realVol;
            $cbm = $boxVol === null ? null : $boxVol * $bx;
            $itemsVol = ($pieceVol !== null && $per > 0) ? $pieceVol * $per : null;

            $sh = !empty($it['shipment_id']) ? ($this->shipments[$it['shipment_id']] ?? null) : null;
            $rr = $this->resolveRate($it, $sh);
            $R[$id] = [
                'boxes_used' => $bx, 'per_used' => $per > 0 ? $per : null, 'auto_per' => $autoPer, 'boxes_derived' => $boxesDerived,
                'qty_entered' => $q > 0 ? $q : null, 'has_box' => $hasBox, 'est' => $est, 'cbm_estimated' => (bool) $useEst,
                'cbm_real' => $realVol === null ? null : $realVol * $bx, 'cbm_est' => $est ? $est['cbm'] * $bx : null,
                'piece_vol' => $pieceVol, 'real_vol' => $realVol, 'items_vol' => $itemsVol, 'box_vol' => $boxVol,
                'pack_increase' => ($hasBox && $itemsVol) ? $realVol / $itemsVol - 1 : null,
                'fit' => ($hasBox && $pieceVol !== null) ? $this->fitCount($L, $W, $H, $iL, $iW, $iH, $pad) : null,
                'total_items' => $totalItems, 'qty' => $qty, 'cbm' => $cbm,
                'rate' => $rr['rate'], 'rate_src' => $rr['src'], 'origin' => $this->originOf($it),
                'item_cost' => $unit === null ? null : $unit * $qty,
                'freight' => $cbm === null ? null : $cbm * $rr['rate'],
                'delivery' => $sh ? 0.0 : $n('delivery'),
                'shipment_id' => $sh['id'] ?? null, 'share' => null,
                'unit_cost' => $unit,
                'margin_target' => $n('target_margin') ?? $defMargin,
            ];
        }
        // one delivery cost per shipment, split across its items
        foreach ($this->shipments as $sh) {
            $m = array_keys(array_filter($items, fn ($i) => (string) ($i['shipment_id'] ?? '') === (string) $sh['id']));
            if (!$m) continue;
            $D = self::num($sh['delivery_cost']) ?? 0.0;
            $how = $sh['allocation'] ?: 'cbm';
            $basis = function ($id) use ($R, $how) {
                $r = $R[$id];
                return match ($how) { 'cbm' => $r['cbm'] ?? 0, 'items' => $r['total_items'] ?? 0, 'value' => $r['item_cost'] ?? 0, default => 1 };
            };
            $tot = array_sum(array_map($basis, $m));
            foreach ($m as $id) {
                $s = $tot > 0 ? $basis($id) / $tot : 1 / count($m);
                $R[$id]['share'] = $s;
                $R[$id]['delivery'] = $D * $s;
            }
        }
        foreach ($items as $id => $it) {
            $r = &$R[$id];
            $r['shipping'] = ($r['freight'] === null && $r['delivery'] === null) ? null : ($r['freight'] ?? 0) + ($r['delivery'] ?? 0);
            $r['total'] = ($r['item_cost'] === null && $r['shipping'] === null) ? null : ($r['item_cost'] ?? 0) + ($r['shipping'] ?? 0);
            $r['per_item'] = ($r['total'] === null || (float) $r['qty'] === 0.0) ? null : $r['total'] / $r['qty'];
            $sell = self::num($it['sell_price'] ?? null);
            $r['sell'] = $sell;
            $r['profit'] = ($sell !== null && $r['per_item'] !== null) ? $sell - $r['per_item'] : null;
            $r['margin'] = ($r['profit'] !== null && $sell) ? $r['profit'] / $sell : null;
            $mt = $r['margin_target'];
            $r['suggested_price'] = ($r['per_item'] !== null && $mt < 100) ? round($r['per_item'] / (1 - $mt / 100), 2) : null;
            unset($r);
        }
        return $R;
    }

    /** Average "real box vs items inside" across items with both sizes. */
    public function learnedIncrease(array $R): ?array
    {
        $v = array_values(array_filter(array_column($R, 'pack_increase'), fn ($x) => $x !== null && $x > -0.5 && $x < 5));
        return $v ? ['avg' => array_sum($v) / count($v), 'n' => count($v)] : null;
    }

    public static function sumOf(array $ids, array $R, string $k): ?float
    {
        $s = 0.0; $any = false;
        foreach ($ids as $id) { $v = $R[$id][$k] ?? null; if ($v !== null) { $s += $v; $any = true; } }
        return $any ? $s : null;
    }
}
