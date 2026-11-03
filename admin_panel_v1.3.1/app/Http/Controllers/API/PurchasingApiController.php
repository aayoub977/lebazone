<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\LanguageService;
use App\Services\PurchasingAi;
use App\Services\PurchasingCalculator as Calc;
use App\Services\PurchasingReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Custom (Lebazone): Purchasing — import costing (formerly the "Orders Online" app).
 * Suppliers, freight rates, shipments and items with landed cost per piece, plus the links into
 * the catalogue: purchase price, receiving stock, creating draft products, suggested selling price.
 */
class PurchasingApiController extends Controller
{
    public const ITEM_STATUS = ['Quote', 'Ordered', 'Paid', 'Shipped', 'Received'];
    public const SHIP_STATUS = ['Planning', 'Booked', 'In transit', 'Arrived', 'Delivered'];

    private function denied()
    {
        $u = auth('api')->user();
        if (!$u) return CommonHelper::responseError('Please log in again', 401);
        $role = $u->role->name ?? '';
        if (in_array($role, ['Super Admin', 'Admin'], true)) return null;
        $perms = (array) ($u->allPermissions ?? []);
        return in_array('purchasing', $perms, true) ? null : CommonHelper::responseError('You do not have access to Purchasing', 403);
    }

    private static function n($v): ?float { return Calc::num($v); }
    private static function s($v, int $max = 500): ?string { $v = trim((string) ($v ?? '')); return $v === '' ? null : mb_substr($v, 0, $max); }
    private static function picUrl(?string $p): ?string { return $p ? asset('storage/' . ltrim($p, '/')) : null; }

    /* ======================= read ======================= */

    public function data(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $c = new Calc();
        $R = $c->computeAll();
        $links = $this->linkInfo($c->items);
        $items = [];
        foreach ($c->items as $id => $it) {
            $it['picture_url'] = self::picUrl($it['picture']);
            $it['photos'] = array_map(fn ($p) => ['path' => $p, 'url' => self::picUrl($p)], self::photoList($it));
            $it['calc'] = $R[$id];
            $it['link'] = $links[$id] ?? null;
            $items[] = $it;
        }
        $rates = array_values(array_map(fn ($r) => $r + ['label' => $c->rateLabel($r), 'history' => json_decode((string) $r['history'], true) ?: []], $c->rates));
        foreach ($rates as &$r) { unset($r['history_raw']); }
        unset($r);
        return CommonHelper::responseWithData([
            'settings' => [
                'cbm_price' => Calc::num($c->setting('cbm_price')) ?? 0,
                'currency' => (string) $c->setting('currency', 'USD'),
                'carton_pad' => $c->cartonPad(),
                'default_margin' => Calc::num($c->setting('default_margin')) ?? 35,
                'ai_provider' => (string) $c->setting('ai_provider', ''),
                'ai_text_provider' => (string) $c->setting('ai_text_provider', ''),
                'ai_has_key' => (string) $c->setting('ai_key', '') !== '',
                'claude_has_key' => (string) $c->setting('claude_key', '') !== '',
                'ai_photos' => PurchasingAi::canPhotos(),
                'ai_text' => PurchasingAi::canText(),
                'ai_styles' => array_keys(PurchasingAi::STYLES),
                'brand_name' => (string) (DB::table('brands')->where('id', (int) $c->setting('brand_id', 0))->value('name') ?? ''),                'default_store_id' => (int) $c->setting('default_store_id', 0) ?: (Store::where('status', 1)->orderBy('id')->value('id') ?: null),
            ],
            'items' => $items,
            'suppliers' => array_values($c->suppliers),
            'shipments' => array_values($c->shipments),
            'rates' => $rates,
            'stores' => Store::where('status', 1)->orderBy('id')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => (string) $s->name]),
            'learned' => $c->learnedIncrease($R),
            'statuses' => ['item' => self::ITEM_STATUS, 'shipment' => self::SHIP_STATUS],
            'allocation' => Calc::ALLOC,
        ]);
    }

    /** Linked Lebazone product info for each item: name, sku, image, stock / prices in the chosen store. */
    private function linkInfo(array $items): array
    {
        $vids = array_values(array_unique(array_filter(array_column($items, 'product_variant_id'))));
        if (!$vids) return [];
        $variants = ProductVariant::whereIn('id', $vids)->get()->keyBy('id');
        $products = Product::with('translations')->whereIn('id', $variants->pluck('product_id'))->get()->keyBy('id');
        $stocks = DB::table('product_variant_store_stocks')->whereIn('product_variant_id', $vids)->get()->groupBy('product_variant_id');
        $out = [];
        foreach ($items as $id => $it) {
            $v = $variants[$it['product_variant_id']] ?? null;
            if (!$v) continue;
            $p = $products[$v->product_id] ?? null;
            $st = collect($stocks[$v->id] ?? [])->firstWhere('store_id', $it['store_id']) ?? collect($stocks[$v->id] ?? [])->first();
            $out[$id] = [
                'product_id' => $v->product_id, 'variant_id' => $v->id, 'sku' => $v->sku,
                'name' => $p ? (string) $p->name : (string) $v->name, 'status' => $p ? (int) $p->status : null,
                'store_id' => $st->store_id ?? null, 'available' => $st ? (int) $st->available : null,
                'price' => $st ? (float) $st->price : null, 'discounted_price' => $st ? (float) $st->discounted_price : null,
                'purchase_price' => $st ? (float) $st->purchase_price : null,
            ];
        }
        return $out;
    }

    /** Live calculation for the item form (nothing is saved). */
    public function preview(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $c = new Calc();
        $id = $request->input('id') ?: '__tmp';
        $draft = $this->itemFields($request) + ['id' => $id];
        $items = $c->items;
        $items[$id] = array_merge($items[$id] ?? [], $draft);
        $R = $c->computeAll($items);
        $r = $R[$id];
        // the same item measured both ways, for the "freight with real box / with estimate" lines
        return CommonHelper::responseWithData(['calc' => $r, 'learned' => $c->learnedIncrease($R), 'carton_pad' => $c->cartonPad()]);
    }

    private function itemFields(Request $q): array
    {
        $rateMode = in_array($q->input('rate_mode'), ['rate', 'custom'], true) ? $q->input('rate_mode') : '';
        return [
            'name' => self::s($q->input('name'), 191) ?? '',
            'sku' => self::s($q->input('sku'), 100),
            'variants' => self::s($q->input('variants')),
            'supplier_id' => self::n($q->input('supplier_id')) ? (int) $q->input('supplier_id') : null,
            'shipment_id' => self::n($q->input('shipment_id')) ? (int) $q->input('shipment_id') : null,
            'status' => in_array($q->input('status'), self::ITEM_STATUS, true) ? $q->input('status') : 'Quote',
            'qty' => self::n($q->input('qty')),
            'boxes' => self::n($q->input('boxes')),
            'per_box' => self::n($q->input('per_box')),
            'box_l' => self::n($q->input('box_l')), 'box_w' => self::n($q->input('box_w')), 'box_h' => self::n($q->input('box_h')),
            'item_l' => self::n($q->input('item_l')), 'item_w' => self::n($q->input('item_w')), 'item_h' => self::n($q->input('item_h')),
            'cbm_basis' => in_array($q->input('cbm_basis'), ['box', 'item'], true) ? $q->input('cbm_basis') : '',
            'unit_cost' => self::n($q->input('unit_cost')),
            'sell_price' => self::n($q->input('sell_price')),
            'target_margin' => self::n($q->input('target_margin')),
            'delivery' => self::n($q->input('delivery')),
            'rate_mode' => $rateMode,
            'rate_id' => $rateMode === 'rate' && self::n($q->input('rate_id')) ? (int) $q->input('rate_id') : null,
            'cbm_custom' => $rateMode === 'custom' ? self::n($q->input('cbm_custom')) : null,
            'notes' => self::s($q->input('notes'), 5000),
            'description' => self::cleanHtml($q->input('description')),
            'barcode' => self::s(preg_replace('/\s+/', '', (string) $q->input('barcode')), 64),
        ];
    }

    /* ======================= items ======================= */

    public function itemSave(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $f = $this->itemFields($request);
        if ($f['name'] === '') return CommonHelper::responseError('Give the item a name');
        // inline "+ new supplier / + new shipment"
        if ($request->filled('new_supplier')) {
            $f['supplier_id'] = DB::table('pur_suppliers')->insertGetId(['name' => self::s($request->new_supplier, 191), 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($request->filled('new_shipment')) {
            $f['shipment_id'] = DB::table('pur_shipments')->insertGetId(['name' => self::s($request->new_shipment, 191), 'delivery_cost' => self::n($request->new_shipment_delivery) ?? 0,
                'allocation' => 'cbm', 'status' => 'Planning', 'ship_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = (int) $request->input('id');
        $old = $id ? DB::table('pur_items')->find($id) : null;
        if ($id && !$old) return CommonHelper::responseError('Item not found');
        if ($request->hasFile('picture')) {
            $f['picture'] = $this->storePicture($request->file('picture'));
            if (!$f['picture']) return CommonHelper::responseError('That file is not a picture we can read');
        } elseif ($request->boolean('remove_picture')) {
            $f['picture'] = null;
        } elseif ($request->filled('copy_picture_from')) {
            $f['picture'] = DB::table('pur_items')->where('id', (int) $request->copy_picture_from)->value('picture');
        }
        // Keep the store-photo list in step with the main picture.
        if ($old && array_key_exists('picture', $f) && $old->photos) {
            $list = array_values(array_filter(self::photoList($old), fn ($p) => $p !== $old->picture));
            if ($f['picture']) array_unshift($list, $f['picture']);
            $f['photos'] = json_encode(array_values(array_unique($list)));
        }
        $f['updated_at'] = now();
        if ($old) {
            DB::table('pur_items')->where('id', $id)->update($f);
        } else {
            $f['created_at'] = now();
            $id = DB::table('pur_items')->insertGetId($f);
        }
        return CommonHelper::responseSuccessWithData($old ? 'Saved' : 'Item added', ['id' => $id]);
    }

    public function itemPicture(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $id = (int) $request->input('id');
        if (!$id || !$request->hasFile('picture')) return CommonHelper::responseError('Choose a picture');
        $p = $this->storePicture($request->file('picture'));
        if (!$p) return CommonHelper::responseError('That file is not a picture we can read');
        DB::table('pur_items')->where('id', $id)->update(['picture' => $p, 'updated_at' => now()]);
        return CommonHelper::responseSuccessWithData('Picture added', ['picture_url' => self::picUrl($p)]);
    }

    public function itemDelete(Request $request)
    {
        if ($e = $this->denied()) return $e;
        // Custom (Lebazone): one item (id) or many at once (ids[]).
        $ids = array_values(array_filter(array_map('intval', (array) ($request->input('ids') ?: [$request->input('id')]))));
        if (!$ids) return CommonHelper::responseError('Nothing selected');
        $n = DB::table('pur_items')->whereIn('id', $ids)->delete();
        return CommonHelper::responseSuccess($n === 1 ? 'Item deleted' : "$n items deleted");
    }

    /** Save an uploaded image (resized to max 1200 px when GD is available) under storage/app/public/purchasing. */
    private function storePicture($file): ?string
    {
        $mime = (string) $file->getMimeType();
        if (!str_starts_with($mime, 'image/')) return null;
        $dir = 'purchasing';
        Storage::disk('public')->makeDirectory($dir);
        $name = $dir . '/' . date('Ymd') . '-' . Str::random(10);
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring(file_get_contents($file->getRealPath()));
            if ($img) {
                [$w, $h] = [imagesx($img), imagesy($img)];
                $k = min(1, 1200 / max($w, $h));
                $out = imagecreatetruecolor(max(1, (int) round($w * $k)), max(1, (int) round($h * $k)));
                imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
                imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
                $path = Storage::disk('public')->path($name . '.jpg');
                imagejpeg($out, $path, 86);
                imagedestroy($img); imagedestroy($out);
                return $name . '.jpg';
            }
        }
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        Storage::disk('public')->putFileAs($dir, $file, basename($name) . '.' . $ext);
        return $name . '.' . $ext;
    }

    /* ======================= suppliers / rates / shipments / settings ======================= */

    public function supplierSave(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $name = self::s($request->input('name'), 191);
        if (!$name) return CommonHelper::responseError('Give the supplier a name');
        $f = ['name' => $name];
        foreach (['company', 'platform', 'contact', 'phone', 'whatsapp', 'wechat', 'email', 'website', 'address', 'city', 'country', 'payment_terms', 'moq', 'bank'] as $k) $f[$k] = self::s($request->input($k));
        $f['notes'] = self::s($request->input('notes'), 5000);
        $f['lead_time'] = self::n($request->input('lead_time'));
        $f['rating'] = self::n($request->input('rating')) ? max(1, min(5, (int) $request->input('rating'))) : null;
        $f['updated_at'] = now();
        $id = (int) $request->input('id');
        if ($id) DB::table('pur_suppliers')->where('id', $id)->update($f);
        else { $f['created_at'] = now(); $id = DB::table('pur_suppliers')->insertGetId($f); }
        return CommonHelper::responseSuccessWithData('Supplier saved', ['id' => $id]);
    }

    public function supplierDelete(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $id = (int) $request->input('id');
        $n = DB::table('pur_items')->where('supplier_id', $id)->update(['supplier_id' => null]);
        DB::table('pur_suppliers')->where('id', $id)->delete();
        return CommonHelper::responseSuccess('Supplier deleted' . ($n ? " · $n item(s) unlinked" : ''));
    }

    public function rateSave(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $price = self::n($request->input('price'));
        if ($price === null) return CommonHelper::responseError('Enter the price per CBM');
        $id = (int) $request->input('id');
        $old = $id ? DB::table('pur_rates')->find($id) : null;
        $history = $old ? (json_decode((string) $old->history, true) ?: []) : [];
        if ($old && (float) $old->price !== $price) $history[] = ['price' => (float) $old->price, 'until' => now()->toDateString()];
        $country = self::s($request->input('country'), 100);
        $auto = $request->boolean('auto');
        if ($auto && $country) {
            DB::table('pur_rates')->whereRaw('LOWER(TRIM(country)) = ?', [mb_strtolower($country)])->where('id', '!=', $id)->update(['auto' => 0]);
        }
        $f = ['name' => self::s($request->input('name'), 191), 'country' => $country, 'mode' => self::s($request->input('mode'), 40), 'price' => $price,
            'auto' => $auto ? 1 : 0, 'notes' => self::s($request->input('notes'), 5000), 'history' => json_encode(array_slice($history, -20)), 'updated_at' => now()];
        if ($old) DB::table('pur_rates')->where('id', $id)->update($f);
        else { $f['created_at'] = now(); $id = DB::table('pur_rates')->insertGetId($f); }
        return CommonHelper::responseSuccessWithData($old ? 'Rate updated · costs recalculated' : 'Rate added', ['id' => $id]);
    }

    public function rateDelete(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $id = (int) $request->input('id');
        DB::table('pur_items')->where('rate_mode', 'rate')->where('rate_id', $id)->update(['rate_mode' => '', 'rate_id' => null]);
        DB::table('pur_shipments')->where('rate_mode', 'rate')->where('rate_id', $id)->update(['rate_mode' => '', 'rate_id' => null]);
        DB::table('pur_rates')->where('id', $id)->delete();
        return CommonHelper::responseSuccess('Rate deleted · items using it now use the automatic rate');
    }

    public function shipmentSave(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $name = self::s($request->input('name'), 191);
        if (!$name) return CommonHelper::responseError('Give the shipment a name');
        $mode = in_array($request->input('rate_mode'), ['rate', 'custom'], true) ? $request->input('rate_mode') : '';
        $f = ['name' => $name, 'status' => in_array($request->input('status'), self::SHIP_STATUS, true) ? $request->input('status') : 'Planning',
            'ship_date' => self::s($request->input('ship_date'), 10), 'eta' => self::s($request->input('eta'), 10),
            'forwarder' => self::s($request->input('forwarder'), 191), 'tracking' => self::s($request->input('tracking'), 191),
            'rate_mode' => $mode, 'rate_id' => $mode === 'rate' ? ((int) $request->input('rate_id') ?: null) : null,
            'cbm_price' => $mode === 'custom' ? self::n($request->input('cbm_price')) : null,
            'delivery_cost' => self::n($request->input('delivery_cost')) ?? 0,
            'allocation' => array_key_exists($request->input('allocation'), Calc::ALLOC) ? $request->input('allocation') : 'cbm',
            'notes' => self::s($request->input('notes'), 5000), 'updated_at' => now()];
        if ($f['rate_mode'] === 'custom' && $f['cbm_price'] === null) $f['rate_mode'] = '';
        $id = (int) $request->input('id');
        if ($id) DB::table('pur_shipments')->where('id', $id)->update($f);
        else { $f['created_at'] = now(); $id = DB::table('pur_shipments')->insertGetId($f); }
        return CommonHelper::responseSuccessWithData('Shipment saved', ['id' => $id]);
    }

    public function shipmentDelete(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $id = (int) $request->input('id');
        $n = DB::table('pur_items')->where('shipment_id', $id)->update(['shipment_id' => null]);
        DB::table('pur_shipments')->where('id', $id)->delete();
        return CommonHelper::responseSuccess('Shipment deleted' . ($n ? " · $n item(s) moved out" : ''));
    }

    public function settingsSave(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $set = [];
        if ($request->has('cbm_price')) $set['cbm_price'] = (string) (self::n($request->cbm_price) ?? 0);
        if ($request->has('currency')) $set['currency'] = (string) self::s($request->currency, 8);
        if ($request->has('carton_pad')) $set['carton_pad'] = (string) (self::n($request->carton_pad) ?? 3);
        if ($request->has('default_margin')) $set['default_margin'] = (string) max(0, min(95, self::n($request->default_margin) ?? 35));
        if ($request->has('default_store_id')) $set['default_store_id'] = (string) (int) $request->default_store_id;
        if ($request->has('ai_provider')) $set['ai_provider'] = in_array($request->ai_provider, ['gemini', 'openai'], true) ? $request->ai_provider : '';
        if ($request->has('ai_text_provider')) $set['ai_text_provider'] = in_array($request->ai_text_provider, ['gemini', 'openai', 'claude'], true) ? $request->ai_text_provider : '';
        // Keys are only replaced when a new one is typed; "clear_*" removes them.
        if ($request->filled('ai_key')) $set['ai_key'] = trim((string) $request->ai_key);
        if ($request->boolean('clear_ai_key')) $set['ai_key'] = '';
        if ($request->filled('claude_key')) $set['claude_key'] = trim((string) $request->claude_key);
        if ($request->boolean('clear_claude_key')) $set['claude_key'] = '';        foreach ($set as $k => $v) DB::table('pur_settings')->updateOrInsert(['key' => $k], ['value' => $v, 'updated_at' => now()]);
        return CommonHelper::responseSuccess('Settings saved');
    }

    /* ======================= links into the Lebazone catalogue ======================= */

    /** Search products/variants to link an item to. */
    public function products(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $q = trim((string) $request->input('search', ''));
        $products = Product::query()->with('translations')
            ->when($q !== '', fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('name', 'like', "%{$q}%")
                  ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"))
                  ->orWhereIn('id', ProductVariant::where('sku', 'like', "%{$q}%")->pluck('product_id'));
            }))
            ->orderByDesc('id')->limit(25)->get();
        $variants = ProductVariant::whereIn('product_id', $products->pluck('id'))->get()->groupBy('product_id');
        $rows = [];
        foreach ($products as $p) {
            foreach ($variants[$p->id] ?? [] as $v) {
                $rows[] = ['product_id' => $p->id, 'variant_id' => $v->id, 'sku' => $v->sku, 'status' => (int) $p->status,
                    'name' => (string) $p->name . (count($variants[$p->id]) > 1 && $v->name && $v->name !== $p->name ? ' — ' . $v->name : ''),
                    'image' => $p->image_url ?? null];
            }
        }
        return CommonHelper::responseWithData($rows);
    }

    public function link(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $item = DB::table('pur_items')->find((int) $request->input('item_id'));
        if (!$item) return CommonHelper::responseError('Item not found');
        if ((int) $request->input('variant_id') === 0) {
            DB::table('pur_items')->where('id', $item->id)->update(['product_id' => null, 'product_variant_id' => null, 'updated_at' => now()]);
            return CommonHelper::responseSuccess('Unlinked from the product');
        }
        $v = ProductVariant::find((int) $request->input('variant_id'));
        if (!$v) return CommonHelper::responseError('Product not found');
        $store = (int) $request->input('store_id') ?: $this->defaultStore();
        DB::table('pur_items')->where('id', $item->id)->update(['product_id' => $v->product_id, 'product_variant_id' => $v->id, 'store_id' => $store, 'updated_at' => now()]);
        // Everything bought through Purchasing goes under the Lebazone brand; a scanned barcode follows the link.
        $brandId = (int) (DB::table('pur_settings')->where('key', 'brand_id')->value('value') ?? 0);
        if ($brandId) DB::table('products')->where('id', $v->product_id)->update(['brand_id' => $brandId]);
        if ($item->barcode && Schema::hasColumn('product_variants', 'barcode') && !$v->barcode) $v->forceFill(['barcode' => $item->barcode])->save();
        return CommonHelper::responseSuccess('Linked to the product');
    }

    private function defaultStore(): ?int
    {
        $s = (int) DB::table('pur_settings')->where('key', 'default_store_id')->value('value');
        return $s ?: (Store::where('status', 1)->orderBy('id')->value('id') ?: null);
    }

    /** Write the landed cost per piece as the product's purchase price (and optionally the suggested selling price). */
    public function applyCost(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $ids = array_filter(array_map('intval', (array) $request->input('item_ids', [$request->input('item_id')])));
        $withPrice = $request->boolean('with_price');
        $c = new Calc();
        $R = $c->computeAll();
        $done = 0; $skipped = [];
        foreach ($ids as $id) {
            $it = $c->items[$id] ?? null;
            if (!$it) continue;
            $r = $R[$id];
            if (!$it['product_variant_id']) { $skipped[] = $it['name'] . ' (not linked)'; continue; }
            if ($r['per_item'] === null) { $skipped[] = $it['name'] . ' (no cost yet)'; continue; }
            $set = ['purchase_price' => round($r['per_item'], 2), 'updated_at' => now()];
            if ($withPrice && $r['suggested_price']) {
                $price = self::n($request->input('price')) ?? ($r['sell'] ?? $r['suggested_price']);
                $set['price'] = $price; $set['discounted_price'] = $price;
            }
            $this->upsertStock((int) $it['product_variant_id'], (int) ($it['store_id'] ?: $this->defaultStore()), $set);
            DB::table('pur_items')->where('id', $id)->update(['cost_applied_at' => now(), 'cost_applied' => $r['per_item'], 'updated_at' => now()]);
            $done++;
        }
        $msg = $done ? "Cost price updated on $done product(s)" : 'Nothing updated';
        if ($skipped) $msg .= ' · skipped: ' . implode(', ', array_slice($skipped, 0, 5));
        return $done ? CommonHelper::responseSuccess($msg) : CommonHelper::responseError($msg);
    }

    private function upsertStock(int $variantId, int $storeId, array $set): void
    {
        $row = DB::table('product_variant_store_stocks')->where('product_variant_id', $variantId)->where('store_id', $storeId)->first();
        if ($row) {
            DB::table('product_variant_store_stocks')->where('id', $row->id)->update($set);
        } else {
            DB::table('product_variant_store_stocks')->insert($set + ['product_variant_id' => $variantId, 'store_id' => $storeId, 'is_listed' => 1,
                'stock_status' => 0, 'is_unlimited_stock' => 0, 'available' => 0, 'min_alert' => 3, 'created_at' => now()]);
        }
    }

    /** Goods arrived: add the pieces to stock and set the purchase price. */
    public function receive(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $c = new Calc();
        $R = $c->computeAll();
        $ids = $request->filled('shipment_id')
            ? array_keys(array_filter($c->items, fn ($i) => (int) $i['shipment_id'] === (int) $request->shipment_id))
            : [(int) $request->input('item_id')];
        $done = 0; $units = 0; $skipped = [];
        DB::transaction(function () use ($ids, $c, $R, $request, &$done, &$units, &$skipped) {
            foreach ($ids as $id) {
                $it = $c->items[$id] ?? null;
                if (!$it) continue;
                if (!$it['product_variant_id']) { $skipped[] = $it['name']; continue; }
                $r = $R[$id];
                $qty = $request->filled('item_id') && $request->filled('qty') ? (int) round((float) $request->qty) : (int) round(($r['total_items'] ?? 0) - (float) $it['received_qty']);
                if ($qty <= 0) continue;
                $store = (int) ($it['store_id'] ?: $this->defaultStore());
                $row = DB::table('product_variant_store_stocks')->where('product_variant_id', $it['product_variant_id'])->where('store_id', $store)->first();
                $available = ($row ? (int) $row->available : 0) + $qty;
                $set = ['available' => $available, 'stock_status' => $available > 0 ? 1 : 0, 'updated_at' => now()];
                if ($r['per_item'] !== null) $set['purchase_price'] = round($r['per_item'], 2);
                $this->upsertStock((int) $it['product_variant_id'], $store, $set);
                try { \App\Helpers\ProductHelper::notifyBackInStock((int) $it['product_variant_id'], $store); } catch (\Throwable $e) {}
                DB::table('pur_items')->where('id', $id)->update(['received_qty' => (float) $it['received_qty'] + $qty, 'received_at' => now(), 'status' => 'Received',
                    'cost_applied_at' => $r['per_item'] !== null ? now() : $it['cost_applied_at'], 'cost_applied' => $r['per_item'] ?? $it['cost_applied'], 'updated_at' => now()]);
                $done++; $units += $qty;
            }
            if ($request->filled('shipment_id')) DB::table('pur_shipments')->where('id', (int) $request->shipment_id)->update(['status' => 'Delivered', 'received_at' => now(), 'updated_at' => now()]);
        });
        $msg = $done ? "Received $units piece(s) into stock on $done product(s)" : 'Nothing received';
        if ($skipped) $msg .= ' · not linked to a product: ' . implode(', ', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? '…' : '');
        return $done ? CommonHelper::responseSuccess($msg) : CommonHelper::responseError($msg);
    }

    /* ======================= listing: description, photos, barcode, AI ======================= */

    /** Item photos in order (falls back to the item's main picture). */
    private static function photoList($it): array
    {
        $it = (array) $it;
        $list = json_decode((string) ($it['photos'] ?? ''), true);
        $list = is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
        if (!$list && !empty($it['picture'])) $list = [$it['picture']];
        return $list;
    }

    private static function cleanHtml($v): ?string
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') return null;
        if ($v === strip_tags($v)) { // plain text → paragraphs
            $v = implode('', array_map(fn ($p) => '<p>' . nl2br(e(trim($p))) . '</p>', preg_split('/\n\s*\n/', $v)));
        }
        return mb_substr(strip_tags($v, '<p><ul><ol><li><strong><b><em><br>'), 0, 20000);
    }

    /** Add photos (upload) to an item. */
    public function photosUpload(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $it = DB::table('pur_items')->find((int) $request->input('id'));
        if (!$it) return CommonHelper::responseError('Save the item first');
        $list = self::photoList($it);
        foreach ((array) $request->file('photos', []) as $file) {
            if ($file && ($p = $this->storePicture($file))) $list[] = $p;
        }
        $list = array_values(array_unique($list));
        DB::table('pur_items')->where('id', $it->id)->update(['photos' => json_encode($list), 'picture' => $it->picture ?: ($list[0] ?? null), 'updated_at' => now()]);
        return CommonHelper::responseSuccessWithData('Photos added', ['photos' => array_map(fn ($p) => ['path' => $p, 'url' => self::picUrl($p)], $list)]);
    }

    /** Save the chosen photos in order (first = main photo on the store). */
    public function photosSave(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $it = DB::table('pur_items')->find((int) $request->input('id'));
        if (!$it) return CommonHelper::responseError('Item not found');
        $paths = array_values(array_unique(array_filter((array) $request->input('photos', []), fn ($p) => is_string($p) && str_starts_with($p, 'purchasing/')
            && !str_contains($p, '..') && Storage::disk('public')->exists($p))));
        DB::table('pur_items')->where('id', $it->id)->update(['photos' => json_encode($paths), 'updated_at' => now()]);
        return CommonHelper::responseSuccessWithData('Photos saved', ['photos' => array_map(fn ($p) => ['path' => $p, 'url' => self::picUrl($p)], $paths)]);
    }

    /** Start AI work in the background: kind=photos (styles[]) or kind=description. */
    public function aiStart(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $it = DB::table('pur_items')->find((int) $request->input('id'));
        if (!$it) return CommonHelper::responseError('Save the item first');
        $kind = $request->input('kind') === 'description' ? 'description' : 'photo';
        if ($kind === 'photo' && !PurchasingAi::canPhotos()) return CommonHelper::responseError('Set up AI photos first: Purchasing → Settings → AI');
        if ($kind === 'description' && !PurchasingAi::canText()) return CommonHelper::responseError('Set up AI descriptions first: Purchasing → Settings → AI');
        if (!$it->picture) return CommonHelper::responseError('Add the item picture first — the AI starts from it');
        $styles = $kind === 'description' ? [null] : array_values(array_intersect((array) $request->input('styles', []), array_keys(PurchasingAi::STYLES)));
        if (!$styles) $styles = ['studio', 'angle', 'lifestyle', 'detail'];
        $ids = [];
        foreach (array_slice($styles, 0, 6) as $style) {
            $jid = DB::table('pur_ai_jobs')->insertGetId(['item_id' => $it->id, 'kind' => $kind, 'style' => $style, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            dispatch(function () use ($jid) { PurchasingAi::runJob($jid); });
            $ids[] = $jid;
        }
        return CommonHelper::responseSuccessWithData($kind === 'description' ? 'Writing the description…' : 'Making ' . count($ids) . ' photo(s)… this takes about a minute', ['jobs' => $ids]);
    }

    public function aiStatus(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $ids = array_map('intval', (array) $request->input('jobs', []));
        $rows = DB::table('pur_ai_jobs')->whereIn('id', $ids ?: [0])->get();
        return CommonHelper::responseWithData($rows->map(fn ($j) => [
            'id' => $j->id, 'kind' => $j->kind, 'style' => $j->style, 'status' => $j->status, 'error' => $j->error,
            'waiting_s' => now()->diffInSeconds($j->created_at, true),
            'result' => $j->kind === 'photo' ? ($j->result ? ['path' => $j->result, 'url' => self::picUrl($j->result)] : null) : $j->result,
        ])->values());
    }

    /** Next free Lebazone code: LBZ + 6 digits (same series as Scan & label). */
    private function nextLbzCode(): string
    {
        $max = 0;
        foreach (ProductVariant::where('sku', 'like', 'LBZ%')->pluck('sku') as $sk) {
            if (preg_match('/^LBZ(\d{6})$/', $sk, $m)) $max = max($max, (int) $m[1]);
        }
        do { $code = 'LBZ' . str_pad((string) (++$max), 6, '0', STR_PAD_LEFT); } while (ProductVariant::where('sku', $code)->exists());
        return $code;
    }

    /** Save a scanned barcode on the item (and on its linked product, if any). */
    public function barcodeSave(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $it = DB::table('pur_items')->find((int) $request->input('id'));
        if (!$it) return CommonHelper::responseError('Item not found');
        $code = self::s(preg_replace('/\s+/', '', (string) $request->input('barcode')), 64);
        DB::table('pur_items')->where('id', $it->id)->update(['barcode' => $code, 'updated_at' => now()]);
        if ($it->product_variant_id && Schema::hasColumn('product_variants', 'barcode')) {
            ProductVariant::where('id', $it->product_variant_id)->update(['barcode' => $code]);
        }
        return CommonHelper::responseSuccess($code ? 'Barcode saved — the packer can scan it now' : 'Barcode removed');
    }

    /** Create a (hidden by default) Lebazone product from an item: name, photo, variants note, cost and suggested price. */
    public function createProduct(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $c = new Calc();
        $R = $c->computeAll();
        $it = $c->items[(int) $request->input('item_id')] ?? null;
        if (!$it) return CommonHelper::responseError('Item not found');
        if ($it['product_variant_id']) return CommonHelper::responseError('This item is already linked to a product');
        $catId = (int) $request->input('category_id');
        if (!$catId || !DB::table('categories')->where('id', $catId)->exists()) return CommonHelper::responseError('Choose a category');
        $r = $R[$it['id']];
        $price = self::n($request->input('price')) ?? $r['sell'] ?? $r['suggested_price'];
        if (!$price || $price <= 0) return CommonHelper::responseError('Enter a selling price');
        $store = (int) $request->input('store_id') ?: $this->defaultStore();
        if (!$store) return CommonHelper::responseError('No active store found');
        $publish = $request->boolean('publish');

        $langId = (int) app(LanguageService::class)->getDefaultLanguage()->id;
        $name = $it['name'];
        // Product code: the item's own SKU if typed, else a Lebazone LBZ code (prints as a scannable label).
        $sku = strtoupper((string) self::s($it['sku'], 60));
        if ($sku === '' || ProductVariant::where('sku', $sku)->exists()) $sku = $this->nextLbzCode();
        $desc = $it['description'] ?? null;
        if (!$desc) $desc = '<p>' . e($name) . '</p>' . ($it['variants'] ? '<p><strong>Options:</strong> ' . e($it['variants']) . '</p>' : '');
        $photos = self::photoList($it);
        $image = $photos[0] ?? $it['picture'];
        if (!$image) return CommonHelper::responseError('Add a picture to the item first — products need a photo');
        $brandId = (int) (DB::table('pur_settings')->where('key', 'brand_id')->value('value') ?? 0);
        if ($brandId && !DB::table('brands')->where('id', $brandId)->exists()) $brandId = 0;
        $short = trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags(str_replace('</p>', ' ', $desc))), 0, 160)) ?: $name;

        $res = app(ProductApisController::class)->save(Request::create('/', 'POST', [
            'language_id' => $langId, 'category_id' => $catId, 'brand_id' => $brandId,
            'sales_channel' => 'ecommerce', 'product_type' => 0, 'status' => $publish ? 1 : 0, 'is_draft' => 0,
            'return_status' => 1, 'return_days' => 10, 'cancelable_status' => 1,
            'till_status_quick' => 2, 'till_status_ecommerce' => 2, 'cod_allowed' => 1, 'total_allowed_quantity' => 10,
            'translations' => json_encode([$langId => ['name' => $name, 'description' => $desc, 'short_description' => $short, 'manufacturer' => 'Lebazone']], JSON_UNESCAPED_UNICODE),
            'variants' => json_encode([[
                'name' => $name, 'sku' => $sku, 'translations' => [$langId => ['name' => $name]],
                'image_path' => $image, 'gallery_paths' => array_values(array_slice($photos, 1)),
                'store_stocks' => [[
                    'store_id' => $store, 'is_listed' => 1, 'stock_status' => 0, 'is_unlimited_stock' => 0, 'available' => 0, 'min_alert' => 3,
                    'price' => $price, 'discounted_price' => $price, 'purchase_price' => $r['per_item'] !== null ? round($r['per_item'], 2) : 0,
                ]],
            ]], JSON_UNESCAPED_UNICODE),
        ]));
        $out = $res->getData(true);
        if (empty($out['status'])) {
            $msg = is_string($out['message'] ?? null) ? $out['message'] : json_encode($out['message'] ?? $out);
            return CommonHelper::responseError('Lebazone did not accept the product: ' . $msg);
        }
        $v = ProductVariant::where('sku', $sku)->first();
        if ($v && !empty($it['barcode']) && Schema::hasColumn('product_variants', 'barcode')) $v->forceFill(['barcode' => $it['barcode']])->save();
        if ($v) {
            DB::table('pur_items')->where('id', $it['id'])->update(['product_id' => $v->product_id, 'product_variant_id' => $v->id, 'store_id' => $store,
                'cost_applied_at' => now(), 'cost_applied' => $r['per_item'], 'updated_at' => now()]);
        }
        return CommonHelper::responseSuccessWithData(($publish ? 'Product created and shown on the store' : 'Draft product created (hidden until you publish it)') . ' · code ' . $sku, ['product_id' => $v->product_id ?? null, 'code' => $sku]);
    }

    /* ======================= exports & reports ======================= */

    public function export(Request $request)
    {
        if ($e = $this->denied()) return $e;
        return (new PurchasingReports(new Calc()))->fullWorkbook();
    }

    public function report(Request $request)
    {
        if ($e = $this->denied()) return $e;
        $rep = new PurchasingReports(new Calc());
        $data = $rep->build((string) $request->input('type', 'items'), $request->only(['from', 'to', 'supplier_id', 'shipment_id', 'status', 'origin']));
        $format = (string) $request->input('format', 'json');
        if ($format === 'json') return CommonHelper::responseWithData($data);
        return $rep->download($data, $format, $request->boolean('pictures'));
    }
}
