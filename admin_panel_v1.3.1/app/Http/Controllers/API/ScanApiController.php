<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantStoreStock;
use App\Models\Store;
use App\Models\SupportedLanguage;
use App\Services\LanguageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Custom (Lebazone): barcode workflow for the admin "Scan & label" screen.
 *  - lookup a scanned code (manufacturer barcode or our own label code / SKU)
 *  - create a product with its colours in one step (stock is kept per colour)
 *  - add another colour to an existing product
 *  - receive stock by scanning
 * Every colour gets its own Lebazone code (SKU, e.g. LBZ000123) that is printed on its label.
 */
class ScanApiController extends ProductApisController
{
    private function langs(): array
    {
        $def = app(LanguageService::class)->getDefaultLanguage();
        $ar = SupportedLanguage::where('code', 'ar')->first();
        $arId = $ar ? (int) Language::where('supported_language_id', $ar->id)->where('system_type', 4)->value('id') : 0;
        return [(int) ($def->id ?? 0), $arId];
    }

    private function shop(): ?Store
    {
        return Store::where('status', 1)->orderBy('id')->first();
    }

    private function url(?string $path): string
    {
        if (!$path) return '';
        return preg_match('#^https?://#', $path) ? $path : asset('storage/' . ltrim($path, '/'));
    }

    /**
     * Custom (Lebazone): the shop's colour attribute, whatever its link name ended up as
     * (a deleted "Color" keeps the slug 'color', so a new one may be 'color-2').
     */
    private function findColorAttribute(): ?Attribute
    {
        return Attribute::where('slug', 'color')->first()
            ?? Attribute::whereIn('slug', ['colour', 'colors', 'colours'])->first()
            ?? Attribute::where('slug', 'like', 'color-%')->orderBy('id')->first()
            ?? Attribute::whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(name)'), ['color', 'colour'])->orderBy('id')->first();
    }

    private function colorAttribute(): Attribute
    {
        $attr = $this->findColorAttribute();
        if (!$attr) {
            // A deleted "Color" still holds the slug - bring it back instead of clashing with it.
            $old = Attribute::onlyTrashed()->where('slug', 'color')->first();
            if ($old) { $old->restore(); $old->status = 1; $old->save(); return $old; }
        }
        if (!$attr) {
            $attr = new Attribute();
            $attr->name = 'Color';
            $attr->slug = 'color';
            $attr->status = 1;
            $attr->save();
            [$en, $ar] = $this->langs();
            if ($en) $attr->saveTranslation($en, ['name' => 'Color']);
            if ($ar) $attr->saveTranslation($ar, ['name' => 'اللون']);
        }
        return $attr;
    }

    private function colorValue(Attribute $attr, string $color): AttributeValue
    {
        $color = trim($color) ?: 'Standard';
        $val = AttributeValue::where('attribute_id', $attr->id)->whereRaw('LOWER(value) = ?', [mb_strtolower($color)])->first();
        if (!$val) {
            $val = new AttributeValue();
            $val->attribute_id = $attr->id;
            $val->value = $color;
            $val->save();
            [$en] = $this->langs();
            if ($en) $val->saveTranslation($en, ['value' => $color]);
        }
        return $val;
    }

    private function linkColorToCategory(Attribute $attr, int $categoryId): void
    {
        if (!DB::table('category_attribute')->where('category_id', $categoryId)->where('attribute_id', $attr->id)->exists()) {
            $row = ['category_id' => $categoryId, 'attribute_id' => $attr->id];
            if (Schema::hasColumn('category_attribute', 'is_overridden_off')) $row['is_overridden_off'] = 0;
            if (Schema::hasColumn('category_attribute', 'created_at')) { $row['created_at'] = now(); $row['updated_at'] = now(); }
            DB::table('category_attribute')->insert($row);
        }
    }

    /** Next free Lebazone code: LBZ + 6 digits. */
    private function nextCode(): string
    {
        $max = 0;
        foreach (ProductVariant::where('sku', 'like', 'LBZ%')->pluck('sku') as $s) {
            if (preg_match('/^LBZ(\d{6})$/', $s, $m)) $max = max($max, (int) $m[1]);
        }
        do {
            $code = 'LBZ' . str_pad((string) (++$max), 6, '0', STR_PAD_LEFT);
        } while (ProductVariant::where('sku', $code)->exists());
        return $code;
    }

    /** Custom (Lebazone): extra photos sent as photos[] (up to 10), stored like the main one. */
    private function storePhotos(Request $request, string $field = 'photos'): array
    {
        $out = [];
        foreach (array_slice((array) $request->file($field, []), 0, 10) as $file) {
            if (!$file || !$file->isValid() || !str_starts_with((string) $file->getMimeType(), 'image/')) continue;
            $out[] = self::squarePhoto($file->getRealPath()) ?? $file->store('catalog/products', 'public');
        }
        return $out;
    }

    private function storePhoto(Request $request, string $field): ?string
    {
        if (!$request->hasFile($field)) return null;
        $file = $request->file($field);
        if (!$file->isValid() || !str_starts_with((string) $file->getMimeType(), 'image/')) return null;
        return self::squarePhoto($file->getRealPath()) ?? $file->store('catalog/products', 'public');
    }

    /**
     * Phone photos are huge (4000 x 3000, several MB) and not square. Turn them into a
     * 1000 x 1000 JPEG: straightened (EXIF), whole product kept, white background around it.
     */
    public static function squarePhoto(string $path, int $size = 1000): ?string
    {
        if (!function_exists('imagecreatefromstring')) return null;
        $data = @file_get_contents($path);
        $img = $data ? @imagecreatefromstring($data) : false;
        if (!$img) return null;
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $o = (int) ($exif['Orientation'] ?? 1);
            if ($o === 3) $img = imagerotate($img, 180, 0);
            elseif ($o === 6) $img = imagerotate($img, -90, 0);
            elseif ($o === 8) $img = imagerotate($img, 90, 0);
        }
        $w = imagesx($img); $h = imagesy($img);
        $scale = $size / max($w, $h);
        $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($size, $size);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        $rel = 'catalog/products/' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8) . '.jpg';
        $abs = storage_path('app/public/' . $rel);
        @mkdir(dirname($abs), 0777, true);
        imagejpeg($out, $abs, 85);
        imagedestroy($img); imagedestroy($out);
        return $rel;
    }

    /** Product with its colours, as the screen shows it. */
    private function payload(int $productId, ?string $matchedCode = null): array
    {
        $product = Product::with(['variants'])->findOrFail($productId);
        [$en] = $this->langs();
        $shop = $this->shop();
        $attr = $this->findColorAttribute();
        $variants = [];
        foreach ($product->variants->sortBy('sort_order') as $v) {
            $color = '';
            if ($attr) {
                $vid = DB::table('product_variant_attribute_values')->where('product_variant_id', $v->id)->where('attribute_id', $attr->id)->value('attribute_value_id');
                if ($vid) $color = (string) AttributeValue::where('id', $vid)->value('value');
            }
            $stock = $shop ? ProductVariantStoreStock::where('product_variant_id', $v->id)->where('store_id', $shop->id)->first() : null;
            $variants[] = [
                'id' => $v->id,
                'code' => $v->sku,
                'barcode' => $v->barcode ?? '',
                'color' => $color ?: 'Standard',
                'image' => $this->url($v->image),
                'price' => $stock ? (float) $stock->price : null,
                'sale_price' => $stock ? (float) $stock->discounted_price : null,
                'stock' => $stock ? (int) $stock->available : 0,
                'matched' => $matchedCode !== null && (strcasecmp((string) $v->sku, $matchedCode) === 0 || strcasecmp((string) ($v->barcode ?? ''), $matchedCode) === 0),
            ];
        }
        $name = $product->variants->first()?->name ?? ('Product #' . $product->id);
        if ($en && method_exists($product, 'getTranslation')) {
            try { $name = $product->getTranslation('name', $en) ?: $name; } catch (\Throwable $e) {}
        }
        return [
            'id' => $product->id,
            'name' => $name,
            'category' => Category::where('id', $product->category_id)->value('name'),
            'category_id' => (int) $product->category_id,
            'brand' => $product->brand_id ? Brand::where('id', $product->brand_id)->value('name') : '',
            'status' => (int) $product->status,
            'size' => Schema::hasColumn('products', 'lbz_size') ? ($product->lbz_size ?: null) : null,
            'variants' => $variants,
        ];
    }

    // ------------------------------------------------------------------ endpoints

    public function options()
    {
        $cats = Category::where('status', 1)->orderBy('row_order')->get(['id', 'name', 'parent_id'])
            ->filter(fn ($c) => !Category::where('parent_id', $c->id)->exists())
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values();
        $brands = Brand::where('status', 1)->orderBy('name')->get(['id', 'name']);
        $attr = $this->findColorAttribute();
        $colors = $attr ? AttributeValue::where('attribute_id', $attr->id)->orderBy('value')->pluck('value') : collect();
        $base = ['Black', 'White', 'Silver', 'Grey', 'Blue', 'Red', 'Green', 'Gold', 'Pink', 'Purple'];
        return CommonHelper::responseWithData([
            'categories' => $cats,
            'brands' => $brands,
            'colors' => $colors->merge($base)->unique(fn ($c) => mb_strtolower($c))->values(),
            'store' => optional($this->shop())->name,
            'currency' => '$',
            'app_name' => \App\Models\Setting::get_value('app_name') ?: 'Lebazone',
        ]);
    }

    public function lookup(Request $request)
    {
        $code = trim((string) $request->input('code', ''));
        if ($code === '') return CommonHelper::responseError('Scan or type a barcode');
        $q = ProductVariant::query()->where('sku', $code);
        if (Schema::hasColumn('product_variants', 'barcode')) $q->orWhere('barcode', $code);
        $productIds = $q->pluck('product_id')->unique()->values();
        if ($productIds->isEmpty()) {
            return CommonHelper::responseWithData(['found' => false, 'code' => $code]);
        }
        return CommonHelper::responseWithData([
            'found' => true,
            'code' => $code,
            'products' => $productIds->map(fn ($id) => $this->payload((int) $id, $code))->values(),
        ]);
    }

    public function search(Request $request)
    {
        $term = trim((string) $request->input('q', ''));
        if (mb_strlen($term) < 2) return CommonHelper::responseWithData([]);
        $ids = ProductVariant::where('name', 'like', "%$term%")->orWhere('sku', $term)
            ->orderByDesc('id')->limit(10)->pluck('product_id')->unique()->values();
        return CommonHelper::responseWithData($ids->map(fn ($id) => $this->payload((int) $id))->values());
    }

    /** Products added from this screen, newest first. */
    public function recent()
    {
        $ids = ProductVariant::where('sku', 'like', 'LBZ0%')->orderByDesc('id')->limit(40)
            ->pluck('product_id')->unique()->take(10)->values();
        return CommonHelper::responseWithData($ids->map(fn ($id) => $this->payload((int) $id))->values());
    }

    public function product(Request $request)
    {
        return CommonHelper::responseWithData($this->payload((int) $request->input('id')));
    }

    /** Create a product with one or more colours. */
    public function create(Request $request)
    {
        $name = trim((string) $request->input('name'));
        $nameAr = trim((string) $request->input('name_ar'));
        $categoryId = (int) $request->input('category_id');
        $price = (float) $request->input('price');
        $sale = $request->filled('sale_price') ? (float) $request->input('sale_price') : $price;
        $cost = $request->filled('cost') ? (float) $request->input('cost') : round($sale * 0.7, 2);
        $colors = json_decode((string) $request->input('colors', '[]'), true) ?: [];
        if ($name === '') return CommonHelper::responseError('Enter the product name');
        if (!$categoryId) return CommonHelper::responseError('Choose a category');
        if ($price <= 0) return CommonHelper::responseError('Enter the price');
        if ($sale > $price) return CommonHelper::responseError('Sale price must not be higher than the price');
        if (!$colors) return CommonHelper::responseError('Add at least one colour');
        $size = strtoupper(trim((string) $request->input('size')));
        if (!in_array($size, self::SIZES, true)) return CommonHelper::responseError('Choose the size: S, M, L or XL');
        $shop = $this->shop();
        if (!$shop) return CommonHelper::responseError('No active store found');

        foreach ($colors as $c) {
            $bc = trim((string) ($c['barcode'] ?? ''));
            if ($bc !== '' && Schema::hasColumn('product_variants', 'barcode')
                && ProductVariant::where('barcode', $bc)->exists()) {
                return CommonHelper::responseError("Barcode $bc already belongs to another product - scan it on the main screen to open that product");
            }
        }

        [$en, $ar] = $this->langs();
        $photo = $this->storePhoto($request, 'photo');
        $more = $this->storePhotos($request);
        if (!$photo && $more) $photo = array_shift($more); // no main photo: the first extra one becomes it
        $warranty = trim((string) $request->input('warranty', ''));
        $desc = trim((string) $request->input('description', ''));
        $html = ($desc !== '' ? '<p>' . nl2br(e($desc)) . '</p>' : '<p>' . e($name) . '</p>')
            . ($warranty && strtolower($warranty) !== 'no warranty' ? '<p><strong>Warranty:</strong> ' . e($warranty) . '</p>' : '');

        $attr = $this->colorAttribute();
        $this->linkColorToCategory($attr, $categoryId);

        $variants = [];
        $barcodes = [];
        foreach (array_values($colors) as $i => $c) {
            $val = $this->colorValue($attr, (string) ($c['color'] ?? ''));
            $code = $this->nextCode();
            while (in_array($code, array_column($variants, 'sku'), true)) $code = 'LBZ' . str_pad((string) ((int) substr($code, 3) + 1), 6, '0', STR_PAD_LEFT);
            $colorPhoto = $this->storePhoto($request, "photo_$i") ?: $photo;
            $vTrans = [$en => ['name' => $name]];
            if ($ar && $nameAr !== '') $vTrans[$ar] = ['name' => $nameAr];
            $qty = max(0, (int) ($c['stock'] ?? 0));
            $variants[] = [
                'name' => $name, 'sku' => $code, 'translations' => $vTrans,
                'image_path' => $colorPhoto, 'gallery_paths' => $more,
                'attribute_values' => [['attribute_id' => $attr->id, 'value_id' => $val->id]],
                'store_stocks' => [[
                    'store_id' => $shop->id, 'is_listed' => 1, 'stock_status' => 1,
                    'is_unlimited_stock' => 0, 'available' => $qty, 'min_alert' => 2,
                    'price' => $price, 'discounted_price' => $sale, 'purchase_price' => $cost,
                ]],
            ];
            $barcodes[$code] = trim((string) ($c['barcode'] ?? ''));
        }

        $trans = [$en => ['name' => $name, 'description' => $html, 'short_description' => $name,
            'manufacturer' => $request->filled('brand_id') ? (string) Brand::where('id', (int) $request->input('brand_id'))->value('name') : '']];
        if ($ar && $nameAr !== '') $trans[$ar] = ['name' => $nameAr, 'description' => '<p>' . e($nameAr) . '</p>', 'short_description' => $nameAr];

        $res = $this->save(Request::create('/', 'POST', [
            'language_id' => $en, 'category_id' => $categoryId, 'brand_id' => (int) $request->input('brand_id', 0),
            'sales_channel' => 'ecommerce', 'product_type' => 0, 'status' => 1, 'is_draft' => 0,
            'return_status' => 1, 'return_days' => 10, 'cancelable_status' => 1,
            'till_status_quick' => 2, 'till_status_ecommerce' => 2, 'cod_allowed' => 1, 'total_allowed_quantity' => 10,
            'translations' => json_encode($trans, JSON_UNESCAPED_UNICODE),
            'variants' => json_encode($variants, JSON_UNESCAPED_UNICODE),
        ]));
        $out = $res->getData(true);
        if (empty($out['status'])) {
            return CommonHelper::responseError(is_string($out['message'] ?? null) ? $out['message'] : 'Could not save the product');
        }
        $productId = (int) ProductVariant::where('sku', array_key_first($barcodes))->value('product_id');
        if (Schema::hasColumn('products', 'lbz_size')) DB::table('products')->where('id', $productId)->update(['lbz_size' => $size]);
        if (Schema::hasColumn('product_variants', 'barcode')) {
            foreach ($barcodes as $code => $bc) {
                if ($bc !== '') ProductVariant::where('sku', $code)->update(['barcode' => $bc]);
            }
        }
        return CommonHelper::responseSuccessWithData('Product saved', $this->payload($productId));
    }

    /** Custom (Lebazone): S/M/L/XL - how much box space one piece takes (see WarehouseApiController::guessBoxes). */
    public const SIZES = ['S', 'M', 'L', 'XL'];

    public function setSize(Request $request)
    {
        $size = strtoupper(trim((string) $request->input('size')));
        if (!in_array($size, self::SIZES, true)) return CommonHelper::responseError('Choose S, M, L or XL');
        $id = (int) $request->input('product_id');
        if (!Product::where('id', $id)->exists()) return CommonHelper::responseError('Product not found');
        DB::table('products')->where('id', $id)->update(['lbz_size' => $size]);
        return CommonHelper::responseSuccessWithData('Size saved', $this->payload($id));
    }

    /** Products that still have no size (older products) - so they can be filled in quickly. */
    public function missingSize()
    {
        if (!Schema::hasColumn('products', 'lbz_size')) return CommonHelper::responseWithData(['count' => 0, 'products' => []]);
        $q = DB::table('products')->whereNull('deleted_at')->where(fn ($w) => $w->whereNull('lbz_size')->orWhere('lbz_size', ''));
        $count = (clone $q)->count();
        $rows = $q->orderByDesc('id')->limit(40)->pluck('id');
        $out = [];
        foreach ($rows as $id) {
            $v = ProductVariant::where('product_id', $id)->orderBy('id')->first();
            $out[] = ['id' => (int) $id, 'name' => $v->name ?? ('#' . $id), 'image' => $this->url($v->image ?? null)];
        }
        return CommonHelper::responseWithData(['count' => $count, 'products' => $out]);
    }

    /** Add a colour to an existing product. */
    public function addColor(Request $request)
    {
        $product = Product::with('variants')->find((int) $request->input('product_id'));
        if (!$product || $product->variants->isEmpty()) return CommonHelper::responseError('Product not found');
        $color = trim((string) $request->input('color'));
        if ($color === '') return CommonHelper::responseError('Enter the colour');
        $bc = trim((string) $request->input('barcode', ''));
        if ($bc !== '' && Schema::hasColumn('product_variants', 'barcode')
            && ProductVariant::where('barcode', $bc)->where('product_id', '!=', $product->id)->exists()) {
            return CommonHelper::responseError("Barcode $bc already belongs to another product");
        }
        $shop = $this->shop();
        $first = $product->variants->sortBy('sort_order')->first();
        $firstStock = ProductVariantStoreStock::where('product_variant_id', $first->id)->where('store_id', $shop->id)->first();
        $price = $request->filled('price') ? (float) $request->input('price') : (float) ($firstStock->price ?? 0);
        if ($request->filled('sale_price')) {
            $sale = (float) $request->input('sale_price');
            if (!$request->filled('price')) $price = max($price, $sale);
        } else {
            // New price typed without a sale price: no sale on this colour.
            $sale = $request->filled('price') ? $price : (float) ($firstStock->discounted_price ?? $price);
        }
        if ($price <= 0) return CommonHelper::responseError('Enter the price');
        if ($sale > $price) return CommonHelper::responseError('Sale price must not be higher than the price');

        $attr = $this->colorAttribute();
        $this->linkColorToCategory($attr, (int) $product->category_id);
        $val = $this->colorValue($attr, $color);

        try {
            $variant = DB::transaction(function () use ($product, $first, $request, $attr, $val, $shop, $price, $sale, $firstStock, $bc) {
            $v = new ProductVariant(['product_id' => $product->id]);
            $v->product_id = $product->id;
            $v->name = $first->name;
            $v->sku = $this->nextCode();
            $v->sort_order = (int) $product->variants->max('sort_order') + 1;
            $more = $this->storePhotos($request);
            $main = $this->storePhoto($request, 'photo') ?: array_shift($more);
            $v->image = $main ?: $first->image;
            if (Schema::hasColumn('product_variants', 'barcode')) $v->barcode = $bc !== '' ? $bc : null;
            $v->save();
            foreach ($more as $path) { // Custom (Lebazone): extra photos -> the colour's gallery
                \App\Models\ProductImages::create(['product_id' => $product->id, 'product_variant_id' => $v->id, 'image' => $path]);
            }
            foreach (DB::table('product_variant_translations')->where('product_variant_id', $first->id)->get() as $t) {
                $v->saveTranslation((int) $t->language_id, ['name' => $t->name]);
            }
            $this->syncVariantAttributeValues($v, [['attribute_id' => $attr->id, 'value_id' => $val->id]]);
            $this->syncVariantStoreStocks($v, [[
                'store_id' => $shop->id, 'is_listed' => 1, 'stock_status' => 1, 'is_unlimited_stock' => 0,
                'available' => max(0, (int) $request->input('stock', 0)), 'min_alert' => 2,
                'price' => $price, 'discounted_price' => $sale,
                'purchase_price' => (float) ($firstStock->purchase_price ?? round($sale * 0.7, 2)),
            ]]);
            // Older single-colour products: give the first variant a colour too.
            if (!DB::table('product_variant_attribute_values')->where('product_variant_id', $first->id)->where('attribute_id', $attr->id)->exists()) {
                $std = $this->colorValue($attr, (string) $request->input('first_color', 'Standard'));
                DB::table('product_variant_attribute_values')->insert(['product_variant_id' => $first->id, 'attribute_id' => $attr->id, 'attribute_value_id' => $std->id]);
            }
            return $v;
            });
        } catch (\Throwable $e) {
            return CommonHelper::responseError($e->getMessage());
        }
        return CommonHelper::responseSuccessWithData('Colour added', $this->payload($product->id, $variant->sku));
    }

    /** Save the manufacturer barcode on one colour (e.g. scanned later). */
    public function setBarcode(Request $request)
    {
        $v = ProductVariant::find((int) $request->input('variant_id'));
        if (!$v) return CommonHelper::responseError('Colour not found');
        $bc = trim((string) $request->input('barcode', ''));
        if ($bc !== '' && ProductVariant::where('barcode', $bc)->where('product_id', '!=', $v->product_id)->exists()) {
            return CommonHelper::responseError("Barcode $bc already belongs to another product");
        }
        $v->barcode = $bc !== '' ? $bc : null;
        $v->save();
        return CommonHelper::responseSuccessWithData('Barcode saved', $this->payload($v->product_id, $bc));
    }

    /** Receive stock: add (or set) the quantity of one colour. */
    public function receive(Request $request)
    {
        $v = ProductVariant::find((int) $request->input('variant_id'));
        $shop = $this->shop();
        if (!$v || !$shop) return CommonHelper::responseError('Colour not found');
        $qty = (int) $request->input('qty', 1);
        $row = ProductVariantStoreStock::where('product_variant_id', $v->id)->where('store_id', $shop->id)->first();
        if (!$row) return CommonHelper::responseError('This colour has no stock record in the store');
        $row->available = $request->input('mode') === 'set' ? max(0, $qty) : max(0, (int) $row->available + $qty);
        $row->stock_status = $row->available > 0 ? 1 : (int) $row->stock_status;
        $row->save();
        return CommonHelper::responseSuccessWithData('Stock updated', $this->payload($v->product_id, $v->sku));
    }
}
