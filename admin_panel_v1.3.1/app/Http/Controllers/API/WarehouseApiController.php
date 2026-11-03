<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom (Lebazone): warehouse order protocol.
 *
 *   new -> assigned (employee) -> picking (scan every product) -> picked
 *       -> packed (boxes counted, box labels printed)
 *       -> stamped (receipt + delivery note stamped, order sheet scanned)   => customer sees "Packed"
 *       -> ready   (every box scanned onto its region shelf)                 => customer sees "Ready for delivery"
 *       -> out     (driver scanned every box)                                => customer sees "Out for delivery"
 *
 * Codes: employee badge EMP0001 · driver badge DRV0001 · order sheet ORD000123 · box BX000123-2
 */
class WarehouseApiController extends Controller
{
    public const REGIONS = [
        'beirut'         => ['Beirut', 'بيروت', '#DC2626'],
        'mount_lebanon'  => ['Mount Lebanon', 'جبل لبنان', '#2563EB'],
        'north'          => ['North', 'الشمال', '#059669'],
        'akkar'          => ['Akkar', 'عكار', '#0D9488'],
        'bekaa'          => ['Bekaa', 'البقاع', '#D97706'],
        'baalbek_hermel' => ['Baalbek-Hermel', 'بعلبك الهرمل', '#92400E'],
        'south'          => ['South', 'الجنوب', '#7C3AED'],
        'nabatieh'       => ['Nabatieh', 'النبطية', '#DB2777'],
    ];
    private const STAGE_LABEL = [
        'new' => 'New', 'assigned' => 'Assigned', 'picking' => 'Picking', 'picked' => 'Picked',
        'packed' => 'Packed - stamp & scan sheet', 'stamped' => 'Stamped - scan boxes onto shelf', 'ready' => 'On the shelf - ready', 'out' => 'With driver',
    ];

    /** Minutes an order may stay in each stage before admins are alerted (0 = no limit). */
    public const DEFAULT_LIMITS = ['new' => 15, 'assigned' => 30, 'picking' => 30, 'picked' => 15, 'packed' => 15, 'stamped' => 15, 'ready' => 240];

    public static function limits(): array
    {
        $saved = json_decode((string) DB::table('settings')->where('variable', 'warehouse_sla')->value('value'), true) ?: [];
        $out = self::DEFAULT_LIMITS;
        foreach ($out as $k => $v) if (isset($saved[$k]) && is_numeric($saved[$k])) $out[$k] = max(0, (int) $saved[$k]);
        return $out;
    }

    public static function stageLabel(string $stage): string
    {
        return self::STAGE_LABEL[$stage] ?? $stage;
    }

    // ------------------------------------------------------------------ helpers

    private function ok(string $message, array $data = [], string $level = 'success')
    {
        return CommonHelper::responseWithData(array_merge(['level' => $level, 'message' => $message], $data));
    }

    private function bad(string $message, array $data = [])
    {
        return $this->ok($message, $data, 'error');
    }

    private function log(int $orderId, string $stage, ?string $actor, ?string $note = null): void
    {
        DB::table('order_fulfillment_logs')->insert([
            'order_id' => $orderId, 'stage' => $stage, 'actor' => $actor, 'note' => $note, 'created_at' => now(),
        ]);
    }

    public static function regionFor(?object $addr, ?string $text = null): string
    {
        $hay = mb_strtolower(trim(($addr->state ?? '') . ' ' . ($addr->city ?? '') . ' ' . ($addr->area ?? '') . ' ' . $text));
        $rules = [
            'baalbek_hermel' => ['baalbek', 'hermel', 'بعلبك', 'الهرمل'],
            'akkar' => ['akkar', 'عكار', 'halba', 'حلبا'],
            'nabatieh' => ['nabatieh', 'nabatiyeh', 'nabatiye', 'النبطية', 'marjayoun', 'bint jbeil', 'hasbaya'],
            'mount_lebanon' => ['mount lebanon', 'mont-liban', 'جبل لبنان', 'baabda', 'metn', 'matn', 'keserwan', 'kesrouan', 'jbeil', 'byblos', 'aley', 'chouf', 'jounieh'],
            'north' => ['north', 'nord', 'الشمال', 'tripoli', 'طرابلس', 'zgharta', 'batroun', 'koura', 'bsharri', 'minieh', 'dennieh'],
            'bekaa' => ['bekaa', 'beqaa', 'béqaa', 'البقاع', 'zahle', 'zahlé', 'زحلة', 'rachaya', 'west bekaa'],
            'south' => ['south', 'sud', 'الجنوب', 'saida', 'sidon', 'صيدا', 'tyre', 'sour', 'صور', 'jezzine'],
            'beirut' => ['beirut', 'beyrouth', 'بيروت'],
        ];
        foreach ($rules as $key => $words) {
            foreach ($words as $w) if ($w !== '' && str_contains($hay, $w)) return $key;
        }
        $lat = (float) ($addr->latitude ?? 0); $lng = (float) ($addr->longitude ?? 0);
        if ($lat && $lng) {
            if ($lat > 33.86 && $lat < 33.915 && $lng > 35.46 && $lng < 35.535) return 'beirut';
            $c = ['mount_lebanon' => [33.92, 35.68], 'north' => [34.36, 35.88], 'akkar' => [34.54, 36.10], 'bekaa' => [33.72, 35.85],
                  'baalbek_hermel' => [34.12, 36.25], 'south' => [33.32, 35.30], 'nabatieh' => [33.36, 35.55]];
            $best = 'mount_lebanon'; $bd = 1e9;
            foreach ($c as $k => [$y, $x]) { $d = ($lat - $y) ** 2 + ($lng - $x) ** 2; if ($d < $bd) { $bd = $d; $best = $k; } }
            return $best;
        }
        return 'beirut';
    }

    private function fulfillment(int $orderId, bool $create = true): ?object
    {
        $f = DB::table('order_fulfillments')->where('order_id', $orderId)->first();
        if ($f || !$create) return $f;
        $order = DB::table('orders')->where('id', $orderId)->first();
        if (!$order) return null;
        $addr = $order->address_id ? DB::table('user_addresses')->where('id', $order->address_id)->first() : null;
        DB::table('order_fulfillments')->insert([
            'order_id' => $orderId, 'region' => self::regionFor($addr, (string) $order->address),
            'stage' => 'new', 'created_at' => now(), 'updated_at' => now(),
        ] + (Schema::hasColumn('order_fulfillments', 'stage_at') ? ['stage_at' => $order->created_at ?? now()] : []) + [
        ]);
        return DB::table('order_fulfillments')->where('order_id', $orderId)->first();
    }

    private function update(int $orderId, array $vals): void
    {
        $vals['updated_at'] = now();
        if (isset($vals['stage']) && Schema::hasColumn('order_fulfillments', 'stage_at')) {
            $cur = DB::table('order_fulfillments')->where('order_id', $orderId)->value('stage');
            if ($cur !== $vals['stage']) { $vals['stage_at'] = now(); $vals['alerted_stage'] = null; }
        }
        DB::table('order_fulfillments')->where('order_id', $orderId)->update($vals);
    }

    private function staff(?int $id): ?object
    {
        return $id ? DB::table('warehouse_staff')->where('id', $id)->where('active', 1)->first() : null;
    }

    /** The employee record linked to the logged-in account (employee logins), or null for managers. */
    private function linkedStaff(): ?object
    {
        static $cache = [];
        $u = auth()->user();
        if (!$u || !Schema::hasColumn('warehouse_staff', 'admin_id')) return null;
        if (array_key_exists($u->id, $cache)) return $cache[$u->id];
        $role = optional($u->role)->name;
        if ($role === 'Order Station') return $cache[$u->id] = null; // a shared screen, not a person
        if (!in_array($role, \App\Http\Middleware\RestrictWarehouseRoles::ROLES, true)) {
            return $cache[$u->id] = DB::table('warehouse_staff')->where('admin_id', $u->id)->first();
        }
        // Employee account (also when it was created under System users): it IS the employee.
        $pack = in_array($role, ['Packer', 'Packer + products'], true) ? 1 : 0;
        $products = in_array($role, ['Product entry', 'Packer + products'], true) ? 1 : 0;
        $name = trim((string) ($u->username ?: strtok((string) $u->email, '@')));
        $s = DB::table('warehouse_staff')->where('admin_id', $u->id)->first()
            ?: DB::table('warehouse_staff')->whereNull('admin_id')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($s) {
            DB::table('warehouse_staff')->where('id', $s->id)->update(['admin_id' => $u->id, 'can_pack' => $pack, 'can_products' => $products,
                'active' => (int) ($u->status ?? 1) === 1 ? 1 : 0, 'updated_at' => now()]);
        } else {
            $id = DB::table('warehouse_staff')->insertGetId(['name' => $name, 'code' => 'TMP' . uniqid(), 'admin_id' => $u->id,
                'can_pack' => $pack, 'can_products' => $products, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('warehouse_staff')->where('id', $id)->update(['code' => 'EMP' . str_pad((string) $id, 4, '0', STR_PAD_LEFT)]);
        }
        return $cache[$u->id] = DB::table('warehouse_staff')->where('admin_id', $u->id)->first();
    }

    /** Employees always act as themselves; managers may pick any employee. */
    private function actingStaffId(Request $request): int
    {
        $own = $this->linkedStaff();
        return $own ? (int) $own->id : (int) $request->input('staff_id');
    }

    private function driver(?int $id): ?object
    {
        return $id ? DB::table('delivery_boys')->where('id', $id)->first() : null;
    }

    private function items(int $orderId): array
    {
        $rows = DB::table('order_items')->where('order_id', $orderId)->whereNotIn('active_status', ['7', '8', 7, 8])->get();
        $out = [];
        foreach ($rows as $r) {
            $v = DB::table('product_variants')->where('id', $r->product_variant_id)->first();
            $size = ($v && Schema::hasColumn('products', 'lbz_size')) ? DB::table('products')->where('id', $v->product_id)->value('lbz_size') : null;
            $attrs = json_decode((string) $r->variant_attributes, true) ?: [];
            $color = collect($attrs)->map(fn ($a) => $a['value'] ?? '')->filter()->implode(' / ');
            $out[] = [
                'id' => $r->id, 'name' => $r->product_name, 'color' => $color, 'qty' => (int) $r->quantity,
                'price' => (float) $r->discounted_price ?: (float) $r->price,
                'code' => $v->sku ?? '', 'barcode' => Schema::hasColumn('product_variants', 'barcode') ? ($v->barcode ?? '') : '',
                'variant_id' => (int) $r->product_variant_id,
                'size' => $size ?: null,
                'image' => !empty($v->image) ? asset('storage/' . ltrim($v->image, '/')) : '',
            ];
        }
        return $out;
    }

    private function detail(int $orderId): ?array
    {
        $o = DB::table('orders')->where('id', $orderId)->first();
        if (!$o) return null;
        $f = $this->fulfillment($orderId);
        $addr = $o->address_id ? DB::table('user_addresses')->where('id', $o->address_id)->first() : null;
        $user = DB::table('users')->where('id', $o->user_id)->first();
        $picked = json_decode((string) $f->picked, true) ?: [];
        $items = array_map(fn ($i) => $i + ['picked' => (int) ($picked[$i['id']] ?? 0)], $this->items($orderId));
        $staff = $this->staff($f->staff_id);
        $driver = $this->driver($f->driver_id);
        $country = DB::table('countries')->where('id', $o->country_id)->first();
        $cod = in_array(strtolower((string) $o->payment_method), ['cod', 'cash on delivery'], true)
            ? (float) ($o->remaining_final ?? $o->final_total) : 0.0;
        return [
            'id' => (int) $o->id,
            'number' => $o->order_number ?: ('#' . $o->id),
            'sheet_code' => 'ORD' . str_pad((string) $o->id, 6, '0', STR_PAD_LEFT),
            'created_at' => (string) $o->created_at,
            'status' => (int) $o->active_status,
            'customer' => $addr->name ?? ($user->name ?? ''),
            'phone' => trim(($addr->country_code ?? '') . ' ' . ($addr->mobile ?? $o->mobile ?? '')),
            'address' => $o->address ?: trim(($addr->address ?? '') . ', ' . ($addr->area ?? '') . ', ' . ($addr->city ?? '')),
            'landmark' => $addr->landmark ?? '',
            'note' => $o->order_note ?? '',
            'region' => $f->region,
            'region_label' => self::REGIONS[$f->region][0] ?? $f->region,
            'region_ar' => self::REGIONS[$f->region][1] ?? '',
            'region_color' => self::REGIONS[$f->region][2] ?? '#111827',
            'stage' => $f->stage,
            'stage_label' => self::STAGE_LABEL[$f->stage] ?? $f->stage,
            'stage_minutes' => $mins = (int) floor((time() - strtotime((string) ($f->stage_at ?? $f->updated_at))) / 60),
            'stage_limit' => $lim = (int) (self::limits()[$f->stage] ?? 0),
            'overdue' => $f->stage !== 'out' && $lim > 0 && $mins >= $lim,
            'staff' => $staff ? ['id' => $staff->id, 'name' => $staff->name] : null,
            'driver' => $driver ? ['id' => $driver->id, 'name' => $driver->name] : null,
            'boxes' => (int) $f->boxes,
            'shelved' => json_decode((string) $f->shelved, true) ?: [],
            'handed' => json_decode((string) $f->handed, true) ?: [],
            'items' => $items,
            'all_picked' => collect($items)->every(fn ($i) => $i['picked'] >= $i['qty']),
            'boxes_guess' => self::guessBoxes($items),
            'missing_size' => count(array_filter($items, fn ($i) => empty($i['size']))),
            'printed_at' => $f->printed_at ?? null,
            'printed_boxes' => (int) ($f->printed_boxes ?? 0),
            'photos' => self::orderPhotos($orderId),
            'payment_method' => $o->payment_method,
            'delivery_charge' => (float) $o->delivery_charge,
            'discount' => (float) ($o->promo_discount ?? 0) + (float) ($o->discount ?? 0),
            'total' => (float) $o->final_total,
            'cod' => $cod,
            'currency' => $o->currency ?: '$',
            'll_rate' => (float) ($country->secondary_exchange_rate ?? 0),
            'logs' => DB::table('order_fulfillment_logs')->where('order_id', $orderId)->orderByDesc('id')->limit(30)->get(),
        ];
    }

    /** Move the customer-facing order status (notifications go out as usual). */
    private function setStatus(int $orderId, int $statusId, ?int $driverId = null): ?string
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        if (!$order || (int) $order->active_status >= $statusId && (int) $order->active_status !== 9) return null;
        try {
            $payload = ['order_id' => $orderId, 'status_id' => $statusId];
            if ($driverId) $payload['delivery_boy_id'] = $driverId;
            $res = app(OrdersApiController::class)->updateStatus(Request::create('/', 'POST', $payload));
            $out = $res->getData(true);
            return empty($out['status']) ? (string) ($out['message'] ?? 'status not updated') : null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    // ------------------------------------------------------------------ endpoints

    public function board(Request $request)
    {
        $ids = DB::table('orders')->whereIn('active_status', ['2', '3', '4', 2, 3, 4])
            ->where(function ($q) { if (Schema::hasColumn('orders', 'delivery_type')) $q->whereNull('delivery_type')->orWhere('delivery_type', '!=', 'pickup'); })
            ->orderBy('id')->limit(300)->pluck('id');
        $today = DB::table('order_fulfillments')->where('stage', 'out')->where('handed_at', '>=', now()->startOfDay())->pluck('order_id');
        $list = [];
        foreach ($ids->merge($today)->unique() as $id) {
            $d = $this->detail((int) $id);
            if ($d) { unset($d['logs']); $list[] = $d; }
        }
        $staff = $this->staffList();
        $drivers = DB::table('delivery_boys')->where('status', 1)->whereNull('deleted_at')->get()
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'mobile' => $d->mobile, 'zones' => self::driverZones($d),
                'login' => DB::table('admins')->where('id', $d->admin_id)->value('email')])->values();
        $regions = collect(self::REGIONS)->map(fn ($r, $k) => ['key' => $k, 'label' => $r[0], 'ar' => $r[1], 'color' => $r[2]])->values();
        return CommonHelper::responseWithData(['orders' => $list, 'staff' => $staff, 'drivers' => $drivers, 'regions' => $regions]);
    }

    public function order(Request $request)
    {
        $d = $this->detail((int) $request->input('id'));
        return $d ? CommonHelper::responseWithData($d) : CommonHelper::responseError('Order not found');
    }

    public function assign(Request $request)
    {
        $orderId = (int) $request->input('order_id');
        $s = $this->staff((int) $request->input('staff_id'));
        $f = $this->fulfillment($orderId);
        if (!$f || !$s) return CommonHelper::responseError('Order or employee not found');
        if (in_array($f->stage, ['ready', 'out'], true)) return CommonHelper::responseError('This order is already ' . (self::STAGE_LABEL[$f->stage] ?? $f->stage));
        $prev = $this->staff($f->staff_id);
        // Re-assigning keeps the progress (items already scanned, boxes) so the new employee continues.
        $this->update($orderId, ['staff_id' => $s->id, 'stage' => $f->stage === 'new' ? 'assigned' : $f->stage, 'assigned_at' => now(), 'alerted_stage' => null]);
        $this->log($orderId, 'assigned', optional(auth()->user())->email, ($prev && $prev->id != $s->id ? 'Re-assigned from ' . $prev->name . ' to ' : 'Assigned to ') . $s->name);
        return CommonHelper::responseSuccessWithData('Assigned to ' . $s->name, $this->detail($orderId));
    }

    public function region(Request $request)
    {
        $orderId = (int) $request->input('order_id');
        $r = (string) $request->input('region');
        if (!isset(self::REGIONS[$r]) || !$this->fulfillment($orderId)) return CommonHelper::responseError('Unknown region');
        $this->update($orderId, ['region' => $r]);
        $this->log($orderId, 'region', optional(auth()->user())->email, 'Region set to ' . self::REGIONS[$r][0]);
        return CommonHelper::responseSuccessWithData('Region updated', $this->detail($orderId));
    }

    public function pack(Request $request)
    {
        $orderId = (int) $request->input('order_id');
        $boxes = max(1, min(50, (int) $request->input('boxes', 1)));
        $s = $this->staff($this->actingStaffId($request));
        $f = $this->fulfillment($orderId);
        if (!$f) return CommonHelper::responseError('Order not found');
        if (!$s) return CommonHelper::responseError('Scan your badge first');
        if ($f->staff_id && (int) $f->staff_id !== (int) $s->id) $this->takeOver($orderId, $f, $s);
        $d = $this->detail($orderId);
        if (!in_array($f->stage, ['picked', 'packed'], true) && !$d['all_picked']) return CommonHelper::responseError('Scan every product first');
        $this->update($orderId, ['stage' => 'packed', 'boxes' => $boxes, 'packed_at' => now(), 'picked_at' => $f->picked_at ?? now(), 'shelved' => null]);
        $this->log($orderId, 'packed', $s->name, "$boxes box(es)");
        $printed = (int) ($f->printed_boxes ?? 0);
        if ($request->boolean('station', true) && Schema::hasTable('print_jobs')) {
            if ($printed !== $boxes) {
                $this->queueJob($orderId, 'boxes', $boxes);
                return CommonHelper::responseSuccessWithData("Packed in $boxes box(es) - the labels are printing at the Order Station", $this->detail($orderId) + ['labels_queued' => true]);
            }
            return CommonHelper::responseSuccessWithData("Packed in $boxes box(es) - use the $boxes labels printed with the sheet", $this->detail($orderId) + ['labels_queued' => false]);
        }
        return CommonHelper::responseSuccessWithData('Packed in ' . $boxes . ' box(es) - print the box labels', $this->detail($orderId));
    }

    /** Re-open an order (e.g. wrong item packed). */
    public function reopen(Request $request)
    {
        $orderId = (int) $request->input('order_id');
        $f = $this->fulfillment($orderId);
        if (!$f) return CommonHelper::responseError('Order not found');
        if ($f->stage === 'out') return CommonHelper::responseError('The driver already has this order');
        $this->update($orderId, ['stage' => $f->staff_id ? 'assigned' : 'new', 'picked' => null, 'shelved' => null, 'handed' => null, 'boxes' => 0]);
        $this->log($orderId, 'reopened', optional(auth()->user())->email, (string) $request->input('reason', ''));
        return CommonHelper::responseSuccessWithData('Order re-opened', $this->detail($orderId));
    }

    /** Any packer may continue any order: the order simply moves to whoever is working on it now. */
    private function takeOver(int $orderId, object $f, object $staff): void
    {
        $prev = $this->staff($f->staff_id);
        DB::table('order_fulfillments')->where('order_id', $orderId)->update(['staff_id' => $staff->id, 'updated_at' => now()]);
        $this->log($orderId, $f->stage, $staff->name, 'Continued' . ($prev ? ' (started by ' . $prev->name . ')' : ''));
    }

    private function brief(array $d): array
    {
        $keep = ['id', 'number', 'customer', 'region', 'region_label', 'region_ar', 'region_color', 'stage', 'stage_label', 'stage_minutes', 'overdue', 'created_at', 'note', 'boxes'];
        return array_intersect_key($d, array_flip($keep)) + ['items' => array_sum(array_column($d['items'], 'qty')), 'staff_name' => $d['staff']['name'] ?? null];
    }

    /** Pack station: the employee's unfinished orders and the new orders waiting for someone. */
    public function queue(Request $request)
    {
        $s = $this->staff($this->actingStaffId($request));
        if (!$s) return CommonHelper::responseError('Choose who is working');
        $mine = []; $waiting = []; $others = [];
        $ids = DB::table('orders')->whereIn('active_status', ['2', '3', 2, 3])
            ->where(function ($q) { if (Schema::hasColumn('orders', 'delivery_type')) $q->whereNull('delivery_type')->orWhere('delivery_type', '!=', 'pickup'); })
            ->orderBy('id')->limit(200)->pluck('id');
        foreach ($ids as $id) {
            $d = $this->detail((int) $id);
            if (!$d) continue;
            $owner = $d['staff']['id'] ?? null;
            $open = in_array($d['stage'], ['picking', 'picked', 'packed', 'stamped'], true);
            if ($open && (int) $owner === (int) $s->id) $mine[] = $this->brief($d);
            elseif ($open) $others[] = $this->brief($d);                       // started by a colleague - anyone can finish it
            elseif (in_array($d['stage'], ['new', 'assigned'], true)) $waiting[] = $this->brief($d);
        }
        // Started orders first, then the oldest assigned ones.
        usort($mine, fn ($a, $b) => (int) ($a['stage'] === 'assigned') <=> (int) ($b['stage'] === 'assigned') ?: $a['id'] <=> $b['id']);
        return CommonHelper::responseWithData(['mine' => $mine, 'others' => $others, 'waiting' => $waiting]);
    }

    public function sla(Request $request)
    {
        if ($request->isMethod('post')) {
            $vals = [];
            foreach (self::DEFAULT_LIMITS as $k => $v) $vals[$k] = max(0, (int) $request->input($k, $v));
            DB::table('settings')->updateOrInsert(['variable' => 'warehouse_sla'], ['value' => json_encode($vals)]);
        }
        return CommonHelper::responseWithData(['limits' => self::limits(), 'labels' => self::STAGE_LABEL]);
    }

    private function staffList()
    {
        $rows = DB::table('warehouse_staff')->orderBy('name')->get();
        $emails = Schema::hasColumn('warehouse_staff', 'admin_id')
            ? DB::table('admins')->whereIn('id', $rows->pluck('admin_id')->filter())->pluck('email', 'id') : collect();
        return $rows->map(function ($r) use ($emails) {
            $r->login = isset($r->admin_id) && $r->admin_id ? ($emails[$r->admin_id] ?? null) : null;
            return $r;
        })->values();
    }

    /** Who is using the panel: an employee login (fixed identity) or a manager. */
    public function me()
    {
        $s = $this->linkedStaff();
        return CommonHelper::responseWithData([
            'employee' => $s ? ['type' => 'staff', 'id' => (int) $s->id, 'name' => $s->name, 'active' => (int) $s->active,
                'can_pack' => (int) ($s->can_pack ?? 1), 'can_products' => (int) ($s->can_products ?? 0)] : null,
        ]);
    }

    private function roleIdFor(bool $pack, bool $products): ?int
    {
        $name = $pack && $products ? 'Packer + products' : ($pack ? 'Packer' : ($products ? 'Product entry' : null));
        if (!$name) return null;
        $q = DB::table('roles')->where('name', $name)->where('guard_name', 'web');
        if (Schema::hasColumn('roles', 'store_id')) $q->whereNull('store_id');
        return (int) $q->value('id') ?: null;
    }

    /**
     * Add / edit an employee. Optional login (email + password) lets them sign in on their own
     * phone or the warehouse tablet and see ONLY their screens (packing and/or adding products).
     */
    public function staffSave(Request $request)
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') return CommonHelper::responseError('Enter the employee name');
        $id = (int) $request->input('id');
        $hasLinks = Schema::hasColumn('warehouse_staff', 'admin_id');
        $pack = (int) $request->input('can_pack', 1) ? 1 : 0;
        $products = (int) $request->input('can_products', 0) ? 1 : 0;
        $active = (int) $request->input('active', 1) ? 1 : 0;
        $email = strtolower(trim((string) $request->input('login', '')));
        $password = (string) $request->input('password', '');
        if (!$pack && !$products) return CommonHelper::responseError('Tick at least one job: packing or adding products');

        $row = ['name' => $name, 'phone' => $request->input('phone'), 'active' => $active, 'updated_at' => now()];
        if ($hasLinks) $row += ['can_pack' => $pack, 'can_products' => $products];
        $existing = $id ? DB::table('warehouse_staff')->where('id', $id)->first() : null;

        // ---- login account
        $adminId = $existing->admin_id ?? null;
        if ($hasLinks && ($email !== '' || $adminId)) {
            $roleId = $this->roleIdFor((bool) $pack, (bool) $products);
            if (!$roleId) return CommonHelper::responseError('Employee roles are missing - run apply-updates.bat');
            $admin = $adminId ? \App\Models\Admin::find($adminId) : null;
            if ($email !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return CommonHelper::responseError('The login must look like an email, e.g. ali@lebazone.store');
                $taken = DB::table('admins')->where('email', $email)->when($admin, fn ($q) => $q->where('id', '!=', $admin->id))->exists();
                if ($taken) return CommonHelper::responseError('This login email is already used by another account');
            }
            if (!$admin && $email !== '') {
                if ($password === '') return CommonHelper::responseError('Choose a password for the new login');
                if (($err = CommonHelper::validatePasswordPolicy($password)) !== null) return CommonHelper::responseError($err);
                $admin = new \App\Models\Admin();
                $admin->created_by = optional(auth()->user())->id;
            } elseif ($admin && $password !== '' && ($err = CommonHelper::validatePasswordPolicy($password)) !== null) {
                return CommonHelper::responseError($err);
            }
            if ($admin) {
                // Never turn a manager account into an employee account.
                if ($admin->exists && !in_array(optional($admin->role)->name, \App\Http\Middleware\RestrictWarehouseRoles::ROLES, true)) {
                    return CommonHelper::responseError('This login belongs to a manager account - use another email');
                }
                $admin->username = $name;
                if ($email !== '') $admin->email = $email;
                if ($password !== '') $admin->password = bcrypt($password);
                $admin->role_id = $roleId;
                $admin->status = $active;
                $admin->save();
                $adminId = $admin->id;
                $row['admin_id'] = $adminId;
            }
        }

        if ($existing) {
            DB::table('warehouse_staff')->where('id', $id)->update($row);
        } else {
            $id = DB::table('warehouse_staff')->insertGetId($row + ['code' => 'TMP' . uniqid(), 'created_at' => now()]);
            DB::table('warehouse_staff')->where('id', $id)->update(['code' => 'EMP' . str_pad((string) $id, 4, '0', STR_PAD_LEFT)]);
        }
        return CommonHelper::responseSuccessWithData('Saved', $this->staffList());
    }

    /** The scanner: badges, order sheets, product codes and box labels all come through here. */
    public function scan(Request $request)
    {
        $code = strtoupper(trim((string) $request->input('code')));
        $staff = $this->staff($this->actingStaffId($request));
        $driver = $this->linkedStaff() ? null : $this->driver((int) $request->input('driver_id'));
        $activeId = (int) $request->input('order_id');
        if ($code === '') return $this->bad('Nothing scanned');

        // ---- badges
        if (preg_match('/^(EMP|DRV)(\d+)$/', $code) && $this->linkedStaff()) {
            return $this->bad('You are logged in as ' . $this->linkedStaff()->name . ' - no badge needed');
        }
        if (preg_match('/^EMP(\d+)$/', $code, $m)) {
            $s = DB::table('warehouse_staff')->where('code', $code)->where('active', 1)->first();
            $open = $s ? DB::table('order_fulfillments')->where('staff_id', $s->id)->whereIn('stage', ['assigned', 'picking', 'picked', 'packed', 'stamped'])->get() : collect();
            $mine = $open->map(function ($f) {
                $n = DB::table('orders')->where('id', $f->order_id)->value('order_number');
                return ['id' => (int) $f->order_id, 'number' => $n, 'stage' => $f->stage, 'stage_label' => self::STAGE_LABEL[$f->stage] ?? $f->stage];
            })->values();
            $hello = 'Hello ' . ($s->name ?? '') . ($mine->count() ? ' - you have ' . $mine->count() . ' unfinished order(s): finish them first' : ' - scan an order sheet');
            return $s ? $this->ok($hello, ['actor' => ['type' => 'staff', 'id' => $s->id, 'name' => $s->name], 'mine' => $mine], $mine->count() ? 'info' : 'success')
                      : $this->bad('Unknown or inactive badge ' . $code);
        }
        if (preg_match('/^DRV(\d+)$/', $code, $m)) {
            $d = $this->driver((int) $m[1]);
            return $d ? $this->ok('Driver ' . $d->name . ' - scan a box to see what to take', ['actor' => ['type' => 'driver', 'id' => $d->id, 'name' => $d->name]])
                      : $this->bad('Unknown driver badge ' . $code);
        }

        // ---- order sheet (receipt / delivery note)
        if (preg_match('/^ORD(\d+)$/', $code, $m)) {
            $orderId = (int) $m[1];
            $f = $this->fulfillment($orderId);
            if (!$f) return $this->bad('Order not found');
            $o = DB::table('orders')->where('id', $orderId)->first();
            if (in_array((int) $o->active_status, [1, 7, 8], true)) return $this->bad('Order ' . $o->order_number . ' is not payable/active (cancelled, returned or awaiting payment)');
            if (!$staff) return $this->bad('Scan your employee badge first');
            if ($f->staff_id && (int) $f->staff_id !== (int) $staff->id && in_array($f->stage, ['picking', 'picked', 'packed', 'stamped'], true)) {
                $this->takeOver($orderId, $f, $staff);
            }
            // A packer must finish putting his last order's boxes on the shelf before starting another.
            if (in_array($f->stage, ['new', 'assigned'], true)) {
                $pending = DB::table('order_fulfillments')->where('staff_id', $staff->id)->where('stage', 'stamped')->where('order_id', '!=', $orderId)->first();
                if ($pending) {
                    $pn = DB::table('orders')->where('id', $pending->order_id)->value('order_number');
                    return $this->bad("Finish order {$pn} first: scan its boxes onto the shelf · خلّص الطلب {$pn} أول", ['order' => $this->detail((int) $pending->order_id)]);
                }
            }
            switch ($f->stage) {
                case 'new':
                case 'assigned':
                    $this->update($orderId, ['staff_id' => $staff->id, 'stage' => 'picking', 'assigned_at' => $f->assigned_at ?? now()]);
                    $this->log($orderId, 'picking', $staff->name, 'Started picking');
                    return $this->ok('Order ' . $o->order_number . ' - scan each product', ['order' => $this->detail($orderId), 'focus' => true]);
                case 'picking':
                    return $this->ok('Continue scanning products for ' . $o->order_number, ['order' => $this->detail($orderId), 'focus' => true]);
                case 'picked':
                    return $this->ok('All picked - enter the number of boxes and print the box labels', ['order' => $this->detail($orderId), 'focus' => true]);
                case 'packed':
                    // Sheet stamped -> customer sees "Packed". The boxes still have to be scanned onto the shelf.
                    $this->update($orderId, ['stage' => 'stamped', 'stamped_at' => now(), 'shelved' => null]);
                    $this->log($orderId, 'stamped', $staff->name, 'Receipt and delivery note stamped');
                    $err = $this->setStatus($orderId, 3);
                    if ($err) $this->log($orderId, 'warning', 'system', 'Customer status: ' . $err);
                    $r = self::REGIONS[$f->region][0] ?? $f->region;
                    return $this->ok("Stamped ✓ Put the {$f->boxes} box(es) on the {$r} shelf and scan each box label", ['order' => $this->detail($orderId)]);
                case 'stamped':
                    $done = count(json_decode((string) $f->shelved, true) ?: []);
                    return $this->ok("Now scan each box label on the shelf ({$done}/{$f->boxes})", ['order' => $this->detail($orderId)], 'info');
                default:
                    return $this->ok('Order ' . $o->order_number . ': ' . (self::STAGE_LABEL[$f->stage] ?? $f->stage), ['order' => $this->detail($orderId), 'focus' => true], 'info');
            }
        }

        // ---- box label
        if (preg_match('/^BX(\d+)-(\d+)$/', $code, $m)) {
            $orderId = (int) $m[1]; $box = (int) $m[2];
            $f = $this->fulfillment($orderId, false);
            if (!$f || $box < 1 || $box > (int) $f->boxes) return $this->bad('Unknown box ' . $code);
            $o = DB::table('orders')->where('id', $orderId)->first();
            $regionLabel = self::REGIONS[$f->region][0] ?? $f->region;

            if ($driver) {
                $zones = self::driverZones($driver);
                if ($zones && !in_array($f->region, $zones, true)) {
                    return $this->bad("Not your zone · مش منطقتك - this box goes to {$regionLabel}. Leave it on the shelf.");
                }
                $pend = $this->photoPending($driver, $orderId);
                if ($pend) {
                    $pn = DB::table('orders')->where('id', $pend->order_id)->value('order_number');
                    return $this->bad("Take the photo of {$pn}'s boxes first · صوّر كراتين {$pn} أول", ['photo_needed' => (int) $pend->order_id]);
                }
                if (!in_array($f->stage, ['ready', 'out'], true)) {
                    return $this->bad("Don't take this box · لا تاخدها: order {$o->order_number} is not ready yet (" . (self::STAGE_LABEL[$f->stage] ?? $f->stage) . ')', ['handover' => $this->handover($f->region, $driver)]);
                }
                if ($f->stage === 'out' && (int) $f->driver_id !== (int) $driver->id) {
                    return $this->bad('This box was already given to another driver', ['handover' => $this->handover($f->region, $driver)]);
                }
                $handed = json_decode((string) $f->handed, true) ?: [];
                $completed = null;
                $msg = "Box $box/{$f->boxes} of {$o->order_number} ({$regionLabel}) checked";
                if (!in_array($box, $handed, true)) $handed[] = $box;
                sort($handed);
                $this->update($orderId, ['handed' => json_encode($handed)]);
                if (count($handed) >= (int) $f->boxes && $f->stage !== 'out') {
                    $this->update($orderId, ['stage' => 'out', 'driver_id' => $driver->id, 'handed_at' => now()]);
                    $this->log($orderId, 'out', $driver->name, 'All boxes handed to driver');
                    $err = $this->setStatus($orderId, 4);
                    $err = $err ?: $this->setStatus($orderId, 5, (int) $driver->id);
                    if ($err) $this->log($orderId, 'warning', 'system', 'Customer status: ' . $err);
                    $msg = "Order {$o->order_number} complete - now take a photo of its boxes";
                    $completed = $orderId;
                }
                $h = $this->handover($f->region, $driver);
                if (!$completed) $msg .= ". {$regionLabel}: take {$h['remaining_boxes']} more box(es)";
                return $this->ok($msg, ['handover' => $h, 'order_id' => $orderId, 'completed' => $completed]);
            }

            if (!$staff) return $this->bad('Scan your badge first (employee badge to shelve, driver badge to collect)');
            if (!in_array($f->stage, ['stamped', 'ready'], true)) {
                $hint = $f->stage === 'packed' ? 'stamp the receipt and delivery note and scan the order sheet first' : (self::STAGE_LABEL[$f->stage] ?? $f->stage);
                return $this->bad("Box not ready for the shelf: $hint", ['order' => $this->detail($orderId)]);
            }
            $shelved = json_decode((string) $f->shelved, true) ?: [];
            if (!in_array($box, $shelved, true)) $shelved[] = $box;
            sort($shelved);
            $this->update($orderId, ['shelved' => json_encode($shelved)]);
            if (count($shelved) >= (int) $f->boxes && $f->stage !== 'ready') {
                $this->update($orderId, ['stage' => 'ready', 'ready_at' => now()]);
                $this->log($orderId, 'ready', $staff->name, "All boxes on the {$regionLabel} shelf");
                $err = $this->setStatus($orderId, 4);
                if ($err) $this->log($orderId, 'warning', 'system', 'Customer status: ' . $err);
                return $this->ok("{$o->order_number} is ready on the {$regionLabel} shelf ✓", ['order' => $this->detail($orderId), 'done' => true]);
            }
            return $this->ok("Box $box/{$f->boxes} on the {$regionLabel} shelf - scan the next box", ['order' => $this->detail($orderId)]);
        }

        // ---- product (picking)
        if (!$activeId && $staff) {
            // No order open on screen: use the employee's order that contains this product.
            foreach (DB::table('order_fulfillments')->where('staff_id', $staff->id)->where('stage', 'picking')->pluck('order_id') as $cid) {
                foreach ($this->items((int) $cid) as $i) {
                    if (strtoupper($i['code']) === $code || ($i['barcode'] !== '' && strtoupper($i['barcode']) === $code)) { $activeId = (int) $cid; break 2; }
                }
            }
        }
        if (!$activeId) return $this->bad('Open an order first (tap Start) · افتح طلب أولاً');
        if (!$staff) return $this->bad('Scan your employee badge first');
        $f = $this->fulfillment($activeId, false);
        if (!$f || $f->stage !== 'picking') return $this->bad('This order is not being picked - scan its order sheet', ['order' => $f ? $this->detail($activeId) : null]);
        if ((int) $f->staff_id !== (int) $staff->id) $this->takeOver($activeId, $f, $staff);
        $items = $this->items($activeId);
        $picked = json_decode((string) $f->picked, true) ?: [];
        $matches = array_values(array_filter($items, fn ($i) => strtoupper($i['code']) === $code || ($i['barcode'] !== '' && strtoupper($i['barcode']) === $code)));
        if (!$matches) return $this->bad('WRONG ITEM - not in this order · منتج غلط، مش بهالطلب', ['order' => $this->detail($activeId)]);
        $target = null;
        foreach ($matches as $i) if ((int) ($picked[$i['id']] ?? 0) < $i['qty']) { $target = $i; break; }
        if (!$target) return $this->bad('Enough already · كفاية: ' . $matches[0]['name'] . ($matches[0]['color'] ? ' (' . $matches[0]['color'] . ')' : ''), ['order' => $this->detail($activeId)]);
        $picked[$target['id']] = (int) ($picked[$target['id']] ?? 0) + 1;
        $this->update($activeId, ['picked' => json_encode($picked)]);
        $done = collect($items)->every(fn ($i) => (int) ($picked[$i['id']] ?? 0) >= $i['qty']);
        if ($done) {
            $this->update($activeId, ['stage' => 'picked', 'picked_at' => now()]);
            $this->log($activeId, 'picked', $staff->name, 'All items scanned');
            return $this->ok('All items picked - enter the number of boxes and print the box labels', ['order' => $this->detail($activeId)]);
        }
        return $this->ok($target['name'] . ($target['color'] ? ' (' . $target['color'] . ')' : '') . " {$picked[$target['id']]}/{$target['qty']}", ['order' => $this->detail($activeId)]);
    }

    // ------------------------------------------------------------------ Order Station

    /** Box space per piece: S 1, M 2, L 4 (a box holds 8). XL always gets its own box. Unknown size counts as M. */
    public const SIZE_UNITS = ['S' => 1, 'M' => 2, 'L' => 4];
    public const BOX_CAPACITY = 8;

    public static function guessBoxes(array $items): int
    {
        $own = 0; $units = 0;
        foreach ($items as $i) {
            $qty = max(0, (int) ($i['qty'] ?? 0));
            $size = strtoupper((string) ($i['size'] ?? ''));
            if ($size === 'XL') { $own += $qty; continue; }
            $units += $qty * (self::SIZE_UNITS[$size] ?? self::SIZE_UNITS['M']);
        }
        return max(1, $own + (int) ceil($units / self::BOX_CAPACITY));
    }

    private function queueJob(int $orderId, string $type, int $boxes = 0): void
    {
        DB::table('print_jobs')->insert(['type' => $type, 'order_id' => $orderId, 'boxes' => $boxes, 'status' => 'pending',
            'requested_by' => optional(auth()->user())->username ?: optional(auth()->user())->email, 'created_at' => now(), 'updated_at' => now()]);
        if ($type === 'boxes') {
            DB::table('order_fulfillments')->where('order_id', $orderId)->update(['printed_boxes' => $boxes]);
        }
    }

    private function openOrderIds()
    {
        return DB::table('orders')->whereIn('active_status', ['2', '3', '4', 2, 3, 4])
            ->where(function ($q) { if (Schema::hasColumn('orders', 'delivery_type')) $q->whereNull('delivery_type')->orWhere('delivery_type', '!=', 'pickup'); })
            ->orderBy('id')->limit(300)->pluck('id');
    }

    /** Order Station screen: orders waiting to be printed/started, plus counts for the whole floor. */
    public function stationOrders()
    {
        // Every open order with the bucket it belongs to, so the station's top buttons can filter.
        $orders = []; $counts = ['waiting' => 0, 'printed' => 0, 'packing' => 0, 'stamp' => 0, 'shelf' => 0, 'ready' => 0];
        foreach ($this->openOrderIds() as $id) {
            $d = $this->detail((int) $id);
            if (!$d) continue;
            $st = $d['stage'];
            $bucket = match (true) {
                in_array($st, ['new', 'assigned'], true) => $d['printed_at'] ? 'printed' : 'waiting',
                in_array($st, ['picking', 'picked'], true) => 'packing',
                $st === 'packed' => 'stamp',
                $st === 'stamped' => 'shelf',
                $st === 'ready' => 'ready',
                default => null,
            };
            if (!$bucket) continue;
            $counts[$bucket]++;
            $orders[] = $this->brief($d) + ['bucket' => $bucket, 'boxes_guess' => $d['boxes_guess'], 'printed_at' => $d['printed_at'],
                'missing_size' => $d['missing_size'],
                'lines' => array_map(fn ($i) => $i['qty'] . ' × ' . $i['name'] . ($i['color'] ? ' (' . $i['color'] . ')' : '') . ($i['size'] ? ' · ' . $i['size'] : ''), $d['items'])];
        }
        usort($orders, fn ($a, $b) => ((int) !empty($a['printed_at'])) <=> ((int) !empty($b['printed_at'])) ?: $a['id'] <=> $b['id']);
        $pending = Schema::hasTable('print_jobs') ? DB::table('print_jobs')->where('status', 'pending')->count() : 0;
        return CommonHelper::responseWithData(['orders' => $orders, 'counts' => $counts, 'pending_prints' => $pending]);
    }

    /** Tap on the Order Station: print the sheet + the guessed number of box labels. */
    public function stationPrint(Request $request)
    {
        $orderId = (int) $request->input('order_id');
        $d = $this->detail($orderId);
        if (!$d) return CommonHelper::responseError('Order not found');
        $boxes = max(1, (int) ($request->input('boxes') ?: $d['boxes_guess']));
        $this->queueJob($orderId, 'sheet');
        $this->queueJob($orderId, 'boxes', $boxes);
        DB::table('order_fulfillments')->where('order_id', $orderId)->update(['printed_at' => now(), 'boxes_guess' => $d['boxes_guess']]);
        $this->log($orderId, 'printed', optional(auth()->user())->username, "Sheet + $boxes box label(s) printed at the Order Station");
        return CommonHelper::responseSuccess("Printing {$d['number']}: sheet + $boxes box label(s)");
    }

    /** Phones / board: send a reprint to the Order Station printer. what = sheet | boxes | all */
    public function queuePrint(Request $request)
    {
        if (!Schema::hasTable('print_jobs')) return CommonHelper::responseError('Run apply-updates.bat first');
        $orderId = (int) $request->input('order_id');
        $d = $this->detail($orderId);
        if (!$d) return CommonHelper::responseError('Order not found');
        $what = (string) $request->input('what', 'all');
        $boxes = max(1, (int) ($request->input('boxes') ?: ($d['boxes'] ?: $d['boxes_guess'])));
        if (in_array($what, ['sheet', 'all'], true)) $this->queueJob($orderId, 'sheet');
        if (in_array($what, ['boxes', 'all'], true)) $this->queueJob($orderId, 'boxes', $boxes);
        if ($what === 'all' && empty($d['printed_at'])) DB::table('order_fulfillments')->where('order_id', $orderId)->update(['printed_at' => now(), 'boxes_guess' => $d['boxes_guess']]);
        return CommonHelper::responseSuccess('Sent to the Order Station printer');
    }

    /** The station PC asks what to print. prints = all | sheets | labels (one window per printer). */
    public function stationJobs(Request $request)
    {
        if (!Schema::hasTable('print_jobs')) return CommonHelper::responseWithData([]);
        $prints = (string) $request->input('prints', 'all');
        $q = DB::table('print_jobs')->where('status', 'pending')->where('created_at', '>=', now()->subHours(12))->orderBy('id')->limit(10);
        if ($prints === 'sheets') $q->where('type', 'sheet');
        if ($prints === 'labels') $q->where('type', 'boxes');
        $out = [];
        foreach ($q->get() as $j) {
            $d = $this->detail((int) $j->order_id);
            if (!$d) { DB::table('print_jobs')->where('id', $j->id)->update(['status' => 'done', 'printed_at' => now()]); continue; }
            unset($d['logs']);
            if ($j->type === 'boxes') $d['boxes'] = (int) $j->boxes;
            $out[] = ['id' => $j->id, 'type' => $j->type, 'order' => $d];
        }
        return CommonHelper::responseWithData($out);
    }

    public function stationJobsDone(Request $request)
    {
        $ids = array_map('intval', (array) $request->input('ids', []));
        if ($ids) DB::table('print_jobs')->whereIn('id', $ids)->update(['status' => 'done', 'printed_at' => now(), 'updated_at' => now()]);
        return CommonHelper::responseSuccess('OK');
    }

    /** Driver web panel / app: scan a box with the logged-in driver (no badge needed). */
    public function driverScan(Request $request)
    {
        $boy = optional(auth()->user())->deliveryBoy;
        if (!$boy) return CommonHelper::responseError('Only a delivery driver can collect boxes');
        $code = strtoupper(trim((string) $request->input('code')));
        if (!preg_match('/^BX\d+-\d+$/', $code)) return $this->bad('Scan a box label (it starts with BX)');
        $request->merge(['code' => $code, 'driver_id' => $boy->id, 'staff_id' => 0, 'order_id' => 0]);
        return $this->scan($request);
    }

    // ------------------------------------------------------------------ driver: photos & board

    public static function orderPhotos(int $orderId): array
    {
        if (!Schema::hasTable('order_photos')) return [];
        return DB::table('order_photos')->where('order_id', $orderId)->orderBy('id')->get()
            ->map(fn ($p) => ['id' => $p->id, 'type' => $p->type, 'url' => asset('storage/' . $p->path), 'at' => (string) $p->created_at])->all();
    }

    private function myDriver(): ?object
    {
        $boy = optional(auth()->user())->deliveryBoy;
        return $boy ? DB::table('delivery_boys')->where('id', $boy->id)->first() : null;
    }

    /** An order already in this driver's car that still has no photo of its boxes. */
    private function photoPending(object $driver, ?int $exceptOrder = null): ?object
    {
        if (!Schema::hasTable('order_photos')) return null;
        $rows = DB::table('order_fulfillments')->where('stage', 'out')->where('driver_id', $driver->id)
            ->where('handed_at', '>=', now()->subDays(2))->get();
        foreach ($rows as $f) {
            if ($exceptOrder && (int) $f->order_id === $exceptOrder) continue;
            $st = (int) DB::table('orders')->where('id', $f->order_id)->value('active_status');
            if (in_array($st, [6, 7, 8], true)) continue;
            if (!DB::table('order_photos')->where('order_id', $f->order_id)->where('type', 'collected')->exists()) return $f;
        }
        return null;
    }

    private function driverRow(object $f): array
    {
        $o = DB::table('orders')->where('id', $f->order_id)->first();
        $addr = $o && $o->address_id ? DB::table('user_addresses')->where('id', $o->address_id)->first() : null;
        $photos = self::orderPhotos((int) $f->order_id);
        $types = array_column($photos, 'type');
        return [
            'id' => (int) $f->order_id, 'number' => $o->order_number ?? '', 'customer' => $addr->name ?? '',
            'phone' => trim(($addr->country_code ?? '') . ' ' . ($addr->mobile ?? $o->mobile ?? '')),
            'address' => $o->address ?? '', 'area' => trim(($addr->area ?? '') . ' ' . ($addr->city ?? '')),
            'lat' => $addr->latitude ?? null, 'lng' => $addr->longitude ?? null,
            'region' => $f->region, 'region_label' => self::REGIONS[$f->region][0] ?? $f->region, 'region_color' => self::REGIONS[$f->region][2] ?? '#111827',
            'boxes' => (int) $f->boxes, 'handed' => json_decode((string) $f->handed, true) ?: [],
            'cod' => $o && in_array(strtolower((string) $o->payment_method), ['cod', 'cash on delivery'], true) ? (float) ($o->remaining_final ?? $o->final_total) : 0,
            'collected_photo' => in_array('collected', $types, true), 'delivered_photo' => in_array('delivered', $types, true),
            'delivered' => $o && (int) $o->active_status === 6, 'photos' => $photos,
        ];
    }

    /** Driver screen: what is waiting on the shelf for his zone + what is in his car. Nothing disappears. */
    public function driverBoard()
    {
        $driver = $this->myDriver();
        if (!$driver) return CommonHelper::responseError('Only a delivery driver can use this');
        $zones = self::driverZones($driver);
        $ready = []; $others = [];
        foreach (DB::table('order_fulfillments')->where('stage', 'ready')->orderBy('ready_at')->get() as $f) {
            if ($zones && !in_array($f->region, $zones, true)) {
                $others[$f->region] = ($others[$f->region] ?? 0) + (int) $f->boxes;
                continue;
            }
            $ready[] = $this->driverRow($f);
        }
        $car = [];
        foreach (DB::table('order_fulfillments')->where('stage', 'out')->where('driver_id', $driver->id)
                     ->where('handed_at', '>=', now()->subDays(2))->orderBy('handed_at')->get() as $f) {
            $row = $this->driverRow($f);
            $st = (int) DB::table('orders')->where('id', $f->order_id)->value('active_status');
            if (in_array($st, [7, 8], true)) continue;
            if ($row['delivered'] && strtotime((string) $f->updated_at) < now()->startOfDay()->timestamp) continue; // yesterday's deliveries drop off
            $car[] = $row;
        }
        $pend = $this->photoPending($driver);
        return CommonHelper::responseWithData([
            'zones' => array_map(fn ($r) => ['region' => $r, 'label' => self::REGIONS[$r][0], 'ar' => self::REGIONS[$r][1], 'color' => self::REGIONS[$r][2]], $zones),
            'ready' => $ready,
            'others' => array_map(fn ($r, $n) => ['label' => self::REGIONS[$r][0] ?? $r, 'boxes' => $n], array_keys($others), array_values($others)),
            'car' => $car,
            'photo_needed' => $pend ? (int) $pend->order_id : null,
        ]);
    }

    /** Resize a phone photo (max 1600px, straightened) and keep it as proof. */
    private static function storeProofPhoto($file): string
    {
        $rel = 'order-photos/' . date('Ym') . '/' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8) . '.jpg';
        $abs = storage_path('app/public/' . $rel);
        @mkdir(dirname($abs), 0777, true);
        $img = function_exists('imagecreatefromstring') ? @imagecreatefromstring((string) @file_get_contents($file->getRealPath())) : false;
        if ($img) {
            if (function_exists('exif_read_data')) {
                $o = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);
                if ($o === 3) $img = imagerotate($img, 180, 0); elseif ($o === 6) $img = imagerotate($img, -90, 0); elseif ($o === 8) $img = imagerotate($img, 90, 0);
            }
            $w = imagesx($img); $h = imagesy($img); $k = min(1, 1600 / max($w, $h));
            $out = imagecreatetruecolor(max(1, (int) ($w * $k)), max(1, (int) ($h * $k)));
            imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
            imagejpeg($out, $abs, 80);
            imagedestroy($img); imagedestroy($out);
            return $rel;
        }
        return $file->store('order-photos/' . date('Ym'), 'public');
    }

    /** Driver: photo of the boxes after collecting (type=collected) or at the door (type=delivered -> order delivered). */
    public function driverPhoto(Request $request)
    {
        $driver = $this->myDriver();
        if (!$driver) return CommonHelper::responseError('Only a delivery driver can use this');
        if (!Schema::hasTable('order_photos')) return CommonHelper::responseError('Run apply-updates.bat first');
        $orderId = (int) $request->input('order_id');
        $type = $request->input('type') === 'delivered' ? 'delivered' : 'collected';
        $file = $request->file('photo');
        if (!$file || !$file->isValid() || !str_starts_with((string) $file->getMimeType(), 'image/')) return CommonHelper::responseError('Take a photo first');
        $f = $this->fulfillment($orderId, false);
        if (!$f || $f->stage !== 'out' || (int) $f->driver_id !== (int) $driver->id) return CommonHelper::responseError('This order is not in your car');
        $o = DB::table('orders')->where('id', $orderId)->first();
        if ($type === 'delivered' && !DB::table('order_photos')->where('order_id', $orderId)->where('type', 'collected')->exists()) {
            return CommonHelper::responseError('Take the photo of the boxes first (when collecting)');
        }
        $path = self::storeProofPhoto($file);
        DB::table('order_photos')->insert(['order_id' => $orderId, 'type' => $type, 'path' => $path, 'delivery_boy_id' => $driver->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->log($orderId, $type === 'delivered' ? 'delivered' : 'collected', $driver->name, ($type === 'delivered' ? 'Delivered' : 'Boxes collected') . ' - photo taken');
        $msg = 'Photo saved ✓';
        if ($type === 'delivered') {
            $err = $this->setStatus($orderId, 6, (int) $driver->id);
            if ($err) { $this->log($orderId, 'warning', 'system', 'Customer status: ' . $err); $msg = 'Photo saved, but the order could not be marked delivered: ' . $err; }
            else $msg = 'Delivered ✓ The customer can see the photo';
            DB::table('order_fulfillments')->where('order_id', $orderId)->update(['updated_at' => now()]);
        }
        // Bell alert for admins, with the photo.
        try {
            $text = ($type === 'delivered' ? '📦 Delivered ' : '🚚 Collected ') . ($o->order_number ?? '#' . $orderId) . ' by ' . $driver->name . ' - photo: ' . asset('storage/' . $path);
            foreach (\App\Models\Admin::whereIn('role_id', [1, 2])->where('status', 1)->get() as $a) {
                $a->notify(new \App\Notifications\WarehouseAlert($orderId, $text));
            }
        } catch (\Throwable $e) {}
        return CommonHelper::responseSuccess($msg);
    }

    /** Customer (website/app): proof photos of his own order. */
    public function customerPhotos(Request $request)
    {
        $orderId = (int) $request->input('order_id');
        $uid = auth()->id();
        if (!$uid || !DB::table('orders')->where('id', $orderId)->where('user_id', $uid)->exists()) return CommonHelper::responseWithData([]);
        return CommonHelper::responseWithData(array_map(fn ($p) => ['type' => $p['type'], 'url' => $p['url'], 'at' => $p['at']], self::orderPhotos($orderId)));
    }

    /** The regions a driver delivers to (empty = all regions). */
    public static function driverZones(?object $driver): array
    {
        if (!$driver || !isset($driver->warehouse_regions)) return [];
        $z = json_decode((string) $driver->warehouse_regions, true) ?: [];
        return array_values(array_filter($z, fn ($r) => isset(self::REGIONS[$r])));
    }

    /** Driver: what is on the shelves now - his zone(s) first, with the orders to take. */
    public function driverReady()
    {
        $boy = optional(auth()->user())->deliveryBoy;
        $driver = $boy ? DB::table('delivery_boys')->where('id', $boy->id)->first() : null;
        $zones = self::driverZones($driver);
        $rows = DB::table('order_fulfillments')->where('stage', 'ready')->orderBy('ready_at')->get();
        $out = [];
        foreach ($rows->groupBy('region') as $region => $list) {
            $mine = !$zones || in_array($region, $zones, true);
            $orders = [];
            if ($mine) {
                foreach ($list as $f) {
                    $o = DB::table('orders')->where('id', $f->order_id)->first();
                    $addr = $o && $o->address_id ? DB::table('user_addresses')->where('id', $o->address_id)->first() : null;
                    $orders[] = ['id' => (int) $f->order_id, 'number' => $o->order_number ?? '', 'customer' => $addr->name ?? '',
                        'area' => trim(($addr->area ?? '') . ' ' . ($addr->city ?? '')), 'boxes' => (int) $f->boxes,
                        'cod' => $o && strtolower((string) $o->payment_method) === 'cod' ? (float) ($o->remaining_final ?? $o->final_total) : 0];
                }
            }
            $out[] = ['region' => $region, 'label' => self::REGIONS[$region][0] ?? $region, 'ar' => self::REGIONS[$region][1] ?? '',
                'color' => self::REGIONS[$region][2] ?? '#111827', 'orders' => $list->count(), 'boxes' => (int) $list->sum('boxes'),
                'mine' => $mine, 'list' => $orders];
        }
        usort($out, fn ($a, $b) => (int) $b['mine'] <=> (int) $a['mine']);
        $zoneInfo = array_map(fn ($r) => ['region' => $r, 'label' => self::REGIONS[$r][0], 'ar' => self::REGIONS[$r][1], 'color' => self::REGIONS[$r][2]], $zones);
        return CommonHelper::responseWithData(['zones' => $zoneInfo, 'all_zones' => !$zones, 'regions' => $out]);
    }

    /**
     * Custom (Lebazone): add / edit a driver from Staff & badges with just the essentials
     * (name, phone, login email + password, zones). Documents and bank details stay optional
     * and can be filled later under Delivery boys.
     */
    public function driverSave(Request $request)
    {
        $id = (int) $request->input('id');
        $name = trim((string) $request->input('name'));
        $mobile = preg_replace('/\D/', '', (string) $request->input('mobile'));
        $email = mb_strtolower(trim((string) $request->input('login')));
        $password = (string) $request->input('password', '');
        if ($name === '') return CommonHelper::responseError('Enter the driver name');
        if ($mobile === '') return CommonHelper::responseError('Enter the driver phone number');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return CommonHelper::responseError('Enter a login email, e.g. driver1@lebazone.store');
        $boy = $id ? \App\Models\DeliveryBoy::find($id) : null;
        $admin = $boy ? \App\Models\Admin::find($boy->admin_id) : null;
        if (DB::table('admins')->whereRaw('LOWER(email) = ?', [$email])->when($admin, fn ($q) => $q->where('id', '!=', $admin->id))->exists()) {
            return CommonHelper::responseError('This login email is already used by another account');
        }
        if (!$admin && $password === '') return CommonHelper::responseError('Choose a password for the driver');
        if ($password !== '' && ($err = CommonHelper::validatePasswordPolicy($password)) !== null) return CommonHelper::responseError($err);
        $zones = array_values(array_filter((array) $request->input('regions', []), fn ($r) => isset(self::REGIONS[$r])));

        DB::beginTransaction();
        try {
            if (!$admin) {
                $admin = new \App\Models\Admin();
                $admin->created_by = optional(auth()->user())->id ?? 0;
            }
            $admin->username = $name;
            $admin->email = $email;
            if ($password !== '') $admin->password = bcrypt($password);
            $admin->role_id = \App\Models\Role::$roleDeliveryBoy;
            $admin->status = 1;
            $admin->save();

            $zone = DB::table('zones')->orderBy('id')->first();
            $country = $zone->country_id ?? DB::table('countries')->orderBy('id')->value('id');
            $boy = $boy ?: new \App\Models\DeliveryBoy();
            $boy->admin_id = $admin->id;
            $boy->name = $name;
            $boy->mobile = $mobile;
            $boy->country_code = $boy->country_code ?: '+961';
            $boy->address = $boy->address ?: 'Lebanon';
            if (!$boy->country_id) $boy->country_id = $country;
            if (!$boy->zone_id && $zone) $boy->zone_id = $zone->id;
            if (!$boy->exists) $boy->status = \App\Models\DeliveryBoy::$statusActive;
            $boy->save();
            if (Schema::hasColumn('delivery_boys', 'warehouse_regions')) {
                DB::table('delivery_boys')->where('id', $boy->id)->update(['warehouse_regions' => $zones ? json_encode($zones) : null]);
            }
            $lang = app(\App\Services\LanguageService::class)->getDefaultLanguage();
            if ($lang && method_exists($boy, 'saveTranslation')) $boy->saveTranslation($lang->id, ['name' => $name, 'address' => $boy->address, 'other_payment_information' => '']);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return CommonHelper::responseError('Could not save the driver: ' . $e->getMessage());
        }
        return CommonHelper::responseSuccess('Driver saved - he logs in with ' . $email);
    }

    /** Manager: which regions a driver delivers to. */
    public function driverRegions(Request $request)
    {
        if (!Schema::hasColumn('delivery_boys', 'warehouse_regions')) return CommonHelper::responseError('Run apply-updates.bat first');
        $id = (int) $request->input('driver_id');
        if (!DB::table('delivery_boys')->where('id', $id)->exists()) return CommonHelper::responseError('Driver not found');
        $regions = array_values(array_filter((array) $request->input('regions', []), fn ($r) => isset(self::REGIONS[$r])));
        DB::table('delivery_boys')->where('id', $id)->update(['warehouse_regions' => $regions ? json_encode($regions) : null]);
        return CommonHelper::responseSuccess('Saved');
    }

    /** What the driver should take for one region: every ready box there. */
    private function handover(string $region, object $driver): array
    {
        $rows = DB::table('order_fulfillments')->where('region', $region)
            ->where(function ($q) use ($driver) {
                $q->where('stage', 'ready')->orWhere(function ($q2) use ($driver) {
                    $q2->where('stage', 'out')->where('driver_id', $driver->id)->where('handed_at', '>=', now()->startOfDay());
                });
            })->orderBy('ready_at')->get();
        $orders = []; $remaining = 0; $total = 0;
        foreach ($rows as $f) {
            $o = DB::table('orders')->where('id', $f->order_id)->first();
            $addr = $o && $o->address_id ? DB::table('user_addresses')->where('id', $o->address_id)->first() : null;
            $handed = json_decode((string) $f->handed, true) ?: [];
            $total += (int) $f->boxes;
            $remaining += max(0, (int) $f->boxes - count($handed));
            $orders[] = [
                'id' => (int) $f->order_id, 'number' => $o->order_number ?? '', 'customer' => $addr->name ?? '',
                'area' => trim(($addr->area ?? '') . ' ' . ($addr->city ?? '')), 'boxes' => (int) $f->boxes,
                'handed' => $handed, 'done' => $f->stage === 'out',
                'cod' => $o && strtolower((string) $o->payment_method) === 'cod' ? (float) ($o->remaining_final ?? $o->final_total) : 0,
            ];
        }
        return ['region' => $region, 'region_label' => self::REGIONS[$region][0] ?? $region,
            'region_color' => self::REGIONS[$region][2] ?? '#111827', 'driver' => ['id' => $driver->id, 'name' => $driver->name],
            'orders' => $orders, 'total_boxes' => $total, 'remaining_boxes' => $remaining];
    }
}
