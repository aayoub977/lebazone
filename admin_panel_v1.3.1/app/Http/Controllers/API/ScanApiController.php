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

    // ---- Custom (Lebazone): seller logins only ever see and change their own products ----
    private $sellerMemo = false;

    /** The seller behind the logged-in account, null for Lebazone staff (-1: a seller login not linked yet). */
    private function currentSellerId(): ?int
    {
        if ($this->sellerMemo !== false) return $this->sellerMemo;
        $u = null;
        try { $u = auth('api')->user(); } catch (\Throwable $e) {}
        if (!$u || !$u->role || $u->role->name !== 'Seller') return $this->sellerMemo = null;
        return $this->sellerMemo = ((int) DB::table('sellers')->where('admin_id', $u->id)->value('id') ?: -1);
    }

    private function ownsProduct(int $productId): bool
    {
        $sid = $this->currentSellerId();
        return $sid === null || (int) DB::table('products')->where('id', $productId)->value('seller_id') === $sid;
    }

    private function ownedOnly($ids)
    {
        return $this->currentSellerId() === null ? collect($ids)->values()
            : collect($ids)->filter(fn ($id) => $this->ownsProduct((int) $id))->values();
    }

    private function notYours()
    {
        return CommonHelper::responseError('Product not found');
    }

    // ---------------- Custom (Lebazone): cost from weight ----------------

    /** Cost per kg ($) and profit margin (%) used to fill in an item's cost and a suggested price. */
    public function costSettings()
    {
        return CommonHelper::responseWithData([
            'cost_per_kg' => (float) (\App\Models\Setting::get_value('lbz_cost_per_kg') ?: 0),
            'margin_percent' => (float) (\App\Models\Setting::get_value('lbz_margin_percent') ?: 0),
        ]);
    }

    public function saveCostSettings(Request $request)
    {
        if ($this->currentSellerId() !== null) return CommonHelper::responseError('Not allowed for your account');
        $kg = (float) $request->input('cost_per_kg', 0);
        $margin = (float) $request->input('margin_percent', 0);
        if ($kg < 0 || $margin < 0 || $margin > 1000) return CommonHelper::responseError('Enter positive numbers');
        DB::table('settings')->updateOrInsert(['variable' => 'lbz_cost_per_kg'], ['value' => (string) round($kg, 4)]);
        DB::table('settings')->updateOrInsert(['variable' => 'lbz_margin_percent'], ['value' => (string) round($margin, 2)]);
        return CommonHelper::responseSuccess('Saved');
    }

    /** Save an item's weight (grams) - used by the admin product form. */
    public function setWeight(Request $request)
    {
        $id = (int) $request->input('product_id');
        if (!Product::where('id', $id)->exists() || !$this->ownsProduct($id)) return CommonHelper::responseError('Product not found');
        $g = max(0, (int) round((float) $request->input('weight_g', 0)));
        if (Schema::hasColumn('products', 'lbz_weight_g')) DB::table('products')->where('id', $id)->update(['lbz_weight_g' => $g ?: null]);
        return CommonHelper::responseSuccess('Weight saved');
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
                'no_barcode' => (int) ($v->lbz_no_barcode ?? 0) === 1, // Custom (Lebazone): uses Lebazone labels
                'color' => $color ?: 'Standard',
                'image' => $this->url($v->image),
                // Custom (Lebazone): the colour's other photos, so one can be made the main photo
                'gallery' => \App\Models\ProductImages::where('product_variant_id', $v->id)->orderBy('id')->get(['id', 'image'])
                    ->map(fn ($g) => ['id' => (int) $g->id, 'url' => $this->url($g->image)])->values()->all(),
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
            'review' => (string) ($product->seller_review ?? ''), // Custom (Lebazone): pending / rejected for seller products
            'weight_g' => (int) ($product->lbz_weight_g ?? 0),
            'review_note' => (string) ($product->seller_review_note ?? ''),
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

    // ---------------- Custom (Lebazone): Excel bulk upload ----------------

    private const BULK_HEADERS = ['Name *', 'Price $ *', 'Sale price $', 'Quantity', 'Colour', 'Category *', 'Box size *', 'Box barcode', 'Photos', 'Arabic name', 'Description'];

    /** Leaf categories (the ones products go in), as the scan screen lists them. */
    private function leafCategories()
    {
        return Category::where('status', 1)->orderBy('row_order')->get(['id', 'name', 'parent_id'])
            ->filter(fn ($c) => !Category::where('parent_id', $c->id)->exists())
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => trim((string) $c->name)])->values();
    }

    /** Excel keeps long barcodes as numbers (6.95E+12) - turn them back into the digits. */
    private function barcodeText($v): string
    {
        if (is_float($v) || is_int($v)) return number_format((float) $v, 0, '', '');
        return preg_replace('/\.0+$/', '', trim((string) $v));
    }

    /** The sample sheet: how to type the products, with category and size dropdowns. */
    public function bulkSample()
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sh = $book->getActiveSheet();
        $sh->setTitle('Products');
        $sh->fromArray(self::BULK_HEADERS, null, 'A1');
        $cats = $this->leafCategories();
        $c1 = $cats[0]['name'] ?? 'Your category';
        $c2 = $cats[1]['name'] ?? $c1;
        $sh->fromArray([
            ['Anker PowerCore 20000mAh', 45, 39, 5, 'Black', $c1, 'M', '6953156206014', 'anker1.jpg, anker2.jpg', '', 'Fast charging power bank'],
            ['Anker PowerCore 20000mAh', 45, 39, 3, 'White', $c1, 'M', '6953156206021', 'anker-white.jpg', '', ''],
            ['USB-C cable 1m', 6, '', 20, '', $c2, 'S', '', 'cable.jpg', '', ''],
        ], null, 'A2');
        $sh->getStyle('A1:K1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sh->getStyle('A1:K1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('DC2626');
        $sh->getStyle('A2:K4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FEF2F2');
        foreach (['A' => 32, 'B' => 11, 'C' => 13, 'D' => 10, 'E' => 12, 'F' => 26, 'G' => 11, 'H' => 18, 'I' => 34, 'J' => 24, 'K' => 36] as $col => $w) {
            $sh->getColumnDimension($col)->setWidth($w);
        }
        // barcodes are text, or Excel turns 6953156206014 into 6.95E+12
        $sh->getStyle('H2:H1000')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
        $sh->setCellValueExplicit('H2', '6953156206014', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sh->setCellValueExplicit('H3', '6953156206021', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sh->freezePane('A2');
        $sh->getComment('A2')->getText()->createTextRun('Example rows - replace or delete them');

        // category list + dropdowns
        $list = $book->createSheet();
        $list->setTitle('Categories');
        $list->setCellValue('A1', 'Categories (pick one in the Products sheet)');
        $list->getStyle('A1')->getFont()->setBold(true);
        foreach ($cats as $i => $c) $list->setCellValue('A' . ($i + 2), $c['name']);
        $list->getColumnDimension('A')->setWidth(40);
        $last = max(2, count($cats) + 1);
        for ($r = 2; $r <= 1000; $r++) {
            $v = $sh->getCell("F$r")->getDataValidation();
            $v->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)
                ->setShowErrorMessage(true)->setErrorTitle('Category')->setError('Pick a category from the list')
                ->setFormula1("'Categories'!\$A\$2:\$A\$$last");
            $z = $sh->getCell("G$r")->getDataValidation();
            $z->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)
                ->setShowErrorMessage(true)->setErrorTitle('Box size')->setError('S, M, L or XL')->setFormula1('"S,M,L,XL"');
        }

        // instructions
        $how = $book->createSheet();
        $how->setTitle('How to fill');
        $lines = [
            ['How to fill the Products sheet'],
            [''],
            ['• One row = one product (or one colour of a product).'],
            ['• Same Name on several rows with different Colours = ONE product with those colours (see the Anker example).'],
            ['• Name, Price, Category and Box size are required. Prices in $. Sale price is optional (leave empty = no sale).'],
            ['• Quantity = how many pieces you have now. Empty = 0.'],
            ['• Category: pick from the dropdown (list is in the Categories sheet).'],
            ['• Box size: how big the item is when packed - S (cable, case, charger), M (power bank, earbuds, mouse), L (speaker, keyboard, router), XL (own box: monitor).'],
            ['• Box barcode: the barcode printed on the product box, if it has one. Empty = Lebazone makes a code and you can print labels.'],
            ['• Photos: the FILE NAMES of the photos, separated by commas, e.g.  anker1.jpg, anker2.jpg  - the first one is the main photo.'],
            ['   When uploading, pick the Excel file AND all these photos together. Names must match the files (capital letters do not matter).'],
            ['• Arabic name and Description are optional.'],
            ['• Delete the 3 example rows before uploading.'],
        ];
        $how->fromArray($lines, null, 'A1');
        $how->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $how->getColumnDimension('A')->setWidth(120);
        $book->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'lbz') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($tmp);
        return response()->download($tmp, 'lebazone-products-sample.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Read an uploaded sheet -> rows the screen previews (and then saves product by product). */
    public function bulkParse(Request $request)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) return CommonHelper::responseError('Choose the Excel file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) return CommonHelper::responseError('Upload an Excel file (.xlsx)');
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
        } catch (\Throwable $e) {
            return CommonHelper::responseError('Could not read this file - save it as .xlsx and try again');
        }
        $sh = $book->getSheetByName('Products') ?? $book->getSheet(0);
        $data = $sh->toArray(null, true, false, false);
        if (count($data) < 2) return CommonHelper::responseError('The sheet has no products');

        // find columns by header text, so moved columns still work
        $hdr = array_map(fn ($h) => strtolower(trim(preg_replace('/[^a-z ]/i', ' ', (string) $h))), $data[0]);
        $find = function (array $words) use ($hdr) {
            foreach ($hdr as $i => $h) foreach ($words as $w) if ($h !== '' && str_starts_with(preg_replace('/\s+/', ' ', $h), $w)) return $i;
            return null;
        };
        $col = [
            'name' => $find(['name']), 'price' => $find(['price']), 'sale' => $find(['sale price', 'sale']),
            'qty' => $find(['quantity', 'qty', 'stock']), 'color' => $find(['colour', 'color']),
            'cat' => $find(['category']), 'size' => $find(['box size', 'size']), 'barcode' => $find(['box barcode', 'barcode']),
            'photos' => $find(['photos', 'photo', 'images']), 'ar' => $find(['arabic']), 'desc' => $find(['description']),
        ];
        if ($col['name'] === null || $col['price'] === null) return CommonHelper::responseError('Use the sample sheet - the Name and Price columns are missing');

        $cats = $this->leafCategories();
        $byName = [];
        foreach ($cats as $c) $byName[mb_strtolower($c['name'])] = $c;
        $cell = fn ($row, $k) => $col[$k] === null ? '' : trim((string) ($row[$col[$k]] ?? ''));
        $num = function ($v) { $v = str_replace([',', '$', ' '], ['.', '', ''], (string) $v); return $v === '' ? '' : (is_numeric($v) ? (float) $v : null); };

        $rows = [];
        foreach (array_slice($data, 1) as $i => $row) {
            $name = $cell($row, 'name');
            if ($name === '' && implode('', array_map(fn ($x) => trim((string) $x), (array) $row)) === '') continue; // empty line
            $errors = [];
            $price = $num($cell($row, 'price'));
            $sale = $num($cell($row, 'sale'));
            $qty = $num($cell($row, 'qty'));
            $catName = $cell($row, 'cat');
            $cat = $byName[mb_strtolower($catName)] ?? null;
            $size = strtoupper($cell($row, 'size'));
            if ($name === '') $errors[] = 'Name is empty';
            if (!is_float($price) || $price <= 0) $errors[] = 'Price is missing or not a number';
            if ($sale === null) $errors[] = 'Sale price is not a number';
            elseif (is_float($sale) && is_float($price) && $sale > $price) $errors[] = 'Sale price is higher than the price';
            if ($qty === null) $errors[] = 'Quantity is not a number';
            if (!$cat) $errors[] = $catName === '' ? 'Category is empty' : "Category \"$catName\" not found";
            if (!in_array($size, self::SIZES, true)) $errors[] = 'Box size must be S, M, L or XL';
            $photos = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $cell($row, 'photos')))));
            $rows[] = [
                'row' => $i + 2, 'name' => $name, 'name_ar' => $cell($row, 'ar'), 'description' => $cell($row, 'desc'),
                'price' => is_float($price) ? $price : null, 'sale_price' => is_float($sale) ? $sale : null,
                'stock' => is_float($qty) ? max(0, (int) $qty) : 0, 'color' => $cell($row, 'color'),
                'category_id' => $cat['id'] ?? null, 'category' => $cat['name'] ?? $catName, 'size' => $size,
                'barcode' => $this->barcodeText($col['barcode'] === null ? '' : ($row[$col['barcode']] ?? '')), 'photos' => $photos, 'errors' => $errors,
            ];
        }
        if (!$rows) return CommonHelper::responseError('The sheet has no products');
        if (count($rows) > 500) return CommonHelper::responseError('Up to 500 rows per file - split it into smaller files');
        return CommonHelper::responseWithData(['rows' => $rows]);
    }

    public function lookup(Request $request)
    {
        $code = trim((string) $request->input('code', ''));
        if ($code === '') return CommonHelper::responseError('Scan or type a barcode');
        $q = ProductVariant::query()->where('sku', $code);
        if (Schema::hasColumn('product_variants', 'barcode')) $q->orWhere('barcode', $code);
        $productIds = $this->ownedOnly($q->pluck('product_id')->unique()->values());
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
        // Custom (Lebazone): also the product's own name (products added in the admin panel name
        // their colours "Gold / Large", so the colour name alone does not find them)
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
        $ids = ProductVariant::where('name', 'like', $like)->orWhere('sku', $term)
            ->orderByDesc('id')->limit(60)->pluck('product_id')
            ->merge(DB::table('products')->whereNull('deleted_at')->where('name', 'like', $like)->orderByDesc('id')->limit(60)->pluck('id'))
            ->merge(Schema::hasTable('product_translations')
                ? DB::table('product_translations')->where('name', 'like', $like)->orderByDesc('product_id')->limit(60)->pluck('product_id') : collect())
            ->map(fn ($x) => (int) $x)->unique()->sortDesc()->values();
        $ids = $this->ownedOnly($ids)->take(10);
        return CommonHelper::responseWithData($ids->map(fn ($id) => $this->payload((int) $id))->values());
    }

    /** Products added from this screen, newest first. */
    public function recent()
    {
        $sid = $this->currentSellerId();
        $ids = $sid !== null
            ? DB::table('products')->where('seller_id', $sid)->whereNull('deleted_at')->orderByDesc('id')->limit(10)->pluck('id')
            // Custom (Lebazone): newest products from anywhere (this screen, the admin panel, bulk upload)
            : DB::table('products')->whereNull('deleted_at')->orderByDesc('id')->limit(10)->pluck('id');
        return CommonHelper::responseWithData($ids->map(fn ($id) => $this->payload((int) $id))->values());
    }

    public function product(Request $request)
    {
        if (!$this->ownsProduct((int) $request->input('id'))) return $this->notYours();
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
        $sellerId = $this->currentSellerId();
        if ($sellerId === -1) return CommonHelper::responseError('Your seller account is not set up yet - contact Lebazone');
        if ($sellerId) { // what Lebazone pays the seller per piece = price minus commission
            $pct = (float) DB::table('sellers')->where('id', $sellerId)->value('commission_percent');
            $cost = round($sale * (1 - $pct / 100), 2);
        }
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
        if ($sellerId) {
            // Custom (Lebazone): sellers must send a complete product - Lebazone checks it before it goes live
            $missing = [];
            if (!$request->hasFile('photo') && !$request->hasFile('photos') && !$request->hasFile('photo_0')) $missing[] = 'at least one photo';
            if (mb_strlen(trim(strip_tags((string) $request->input('description', '')))) < 20) $missing[] = 'a description (at least 20 characters)';
            foreach (array_values($colors) as $i => $c) {
                if ((int) ($c['stock'] ?? 0) < 1) { $missing[] = 'the quantity of every colour (at least 1)'; break; }
            }
            if (count($colors) > 1) {
                foreach ($colors as $c) if (trim((string) ($c['color'] ?? '')) === '') { $missing[] = 'the colour name of every colour'; break; }
            }
            if ($missing) return CommonHelper::responseError('Please add ' . implode(', ', $missing) . ' - Lebazone needs full details to approve your product');
        }

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
        if ($request->filled('weight_g') && Schema::hasColumn('products', 'lbz_weight_g')) {
            DB::table('products')->where('id', $productId)->update(['lbz_weight_g' => max(0, (int) round((float) $request->input('weight_g'))) ?: null]);
        }
        if ($sellerId) {
            // a seller's product stays off the shop until Lebazone approves it
            DB::table('products')->where('id', $productId)->update(['seller_id' => $sellerId, 'status' => 0, 'seller_review' => 'pending', 'seller_review_note' => null]);
            try {
                $sname = (string) DB::table('sellers')->where('id', $sellerId)->value('business_name');
                foreach (\App\Models\Admin::whereIn('role_id', [1, 2])->where('status', 1)->get() as $a) {
                    $a->notify(new \App\Notifications\SellerProductAlert($productId, '🛍 ' . $sname . ' added "' . $name . '" - waiting for your approval (Sellers page)'));
                }
            } catch (\Throwable $e) { /* the notification is a nice-to-have */ }
        }
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
        if (!Product::where('id', $id)->exists() || !$this->ownsProduct($id)) return CommonHelper::responseError('Product not found');
        DB::table('products')->where('id', $id)->update(['lbz_size' => $size]);
        return CommonHelper::responseSuccessWithData('Size saved', $this->payload($id));
    }

    /** Products that still have no size (older products) - so they can be filled in quickly. */
    public function missingSize()
    {
        if (!Schema::hasColumn('products', 'lbz_size')) return CommonHelper::responseWithData(['count' => 0, 'products' => []]);
        $q = DB::table('products')->whereNull('deleted_at')->where(fn ($w) => $w->whereNull('lbz_size')->orWhere('lbz_size', ''));
        if (($sid = $this->currentSellerId()) !== null) $q->where('seller_id', $sid);
        $count = (clone $q)->count();
        $rows = $q->orderByDesc('id')->limit(40)->pluck('id');
        $out = [];
        foreach ($rows as $id) {
            $v = ProductVariant::where('product_id', $id)->orderBy('id')->first();
            $out[] = ['id' => (int) $id, 'name' => $v->name ?? ('#' . $id), 'image' => $this->url($v->image ?? null)];
        }
        return CommonHelper::responseWithData(['count' => $count, 'products' => $out]);
    }

    /**
     * Custom (Lebazone): products with a colour that has no box barcode yet (e.g. added from the
     * admin panel), newest first - pick one on the phone and scan its box to save the barcode.
     */
    public function noBarcode(Request $request)
    {
        $term = trim((string) $request->input('q', ''));
        $q = DB::table('product_variants as pv')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->whereNull('p.deleted_at')
            ->where(fn ($w) => $w->whereNull('pv.barcode')->orWhere('pv.barcode', ''));
        // colours marked "no barcode - Lebazone labels" in the admin panel don't need a scan
        if (Schema::hasColumn('product_variants', 'lbz_no_barcode')) $q->where(fn ($w) => $w->whereNull('pv.lbz_no_barcode')->orWhere('pv.lbz_no_barcode', 0));
        if (Schema::hasColumn('product_variants', 'deleted_at')) $q->whereNull('pv.deleted_at');
        if (($sid = $this->currentSellerId()) !== null) $q->where('p.seller_id', $sid);
        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
            $q->where(fn ($w) => $w->where('p.name', 'like', $like)->orWhere('pv.name', 'like', $like)->orWhere('pv.sku', $term));
        }
        $count = (clone $q)->distinct()->count('pv.product_id');
        $ids = $q->select('pv.product_id')->groupBy('pv.product_id')->orderByDesc('pv.product_id')->limit(15)->pluck('pv.product_id');
        $out = $ids->map(function ($id) {
            $p = $this->payload((int) $id);
            $p['variants'] = array_values(array_filter($p['variants'], fn ($v) => $v['barcode'] === '' && empty($v['no_barcode'])));
            return $p;
        })->values();
        return CommonHelper::responseWithData(['count' => $count, 'products' => $out]);
    }

    /** Add a colour to an existing product. */
    public function addColor(Request $request)
    {
        $product = Product::with('variants')->find((int) $request->input('product_id'));
        if (!$product || $product->variants->isEmpty() || !$this->ownsProduct((int) $product->id)) return CommonHelper::responseError('Product not found');
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
    /**
     * Custom (Lebazone): make one of a colour's gallery photos its main photo. The two swap
     * places (the old main photo moves into the gallery); no file is deleted. The product's own
     * picture follows when it was this colour's main photo.
     */
    public function setMainPhoto(Request $request)
    {
        $v = ProductVariant::find((int) $request->input('variant_id'));
        if (!$v || !$this->ownsProduct((int) $v->product_id)) return CommonHelper::responseError('Colour not found');
        $img = \App\Models\ProductImages::where('product_variant_id', $v->id)->where('id', (int) $request->input('image_id'))->first();
        if (!$img) return CommonHelper::responseError('Photo not found');
        DB::transaction(function () use ($v, $img) {
            $old = (string) $v->image;
            $new = (string) $img->image;
            $product = Product::find($v->product_id);
            $productFollows = $product && ((string) $product->image === $old || (string) $product->image === ''
                || (int) ProductVariant::where('product_id', $v->product_id)->orderBy('sort_order')->orderBy('id')->value('id') === (int) $v->id);
            $v->image = $new;
            $v->save();
            if ($old !== '') { $img->image = $old; $img->save(); } else { $img->delete(); }
            if ($productFollows) { $product->image = $new; $product->save(); }
        });
        return CommonHelper::responseWithData($this->payload((int) $v->product_id));
    }

    public function setBarcode(Request $request)
    {
        $v = ProductVariant::find((int) $request->input('variant_id'));
        if (!$v || !$this->ownsProduct((int) $v->product_id)) return CommonHelper::responseError('Colour not found');
        $bc = trim((string) $request->input('barcode', ''));
        if ($bc !== '' && ProductVariant::where('barcode', $bc)->where('product_id', '!=', $v->product_id)->exists()) {
            return CommonHelper::responseError("Barcode $bc already belongs to another product");
        }
        $v->barcode = $bc !== '' ? $bc : null;
        if ($bc !== '' && Schema::hasColumn('product_variants', 'lbz_no_barcode')) $v->lbz_no_barcode = 0;
        if (trim((string) $v->sku) === '') $v->sku = $this->nextCode(); // Custom (Lebazone): a Lebazone code for items that had none
        $v->save();
        return CommonHelper::responseSuccessWithData('Barcode saved', $this->payload($v->product_id, $bc));
    }

    /** Receive stock: add (or set) the quantity of one colour. */
    public function receive(Request $request)
    {
        $v = ProductVariant::find((int) $request->input('variant_id'));
        $shop = $this->shop();
        if (!$v || !$shop || !$this->ownsProduct((int) $v->product_id)) return CommonHelper::responseError('Colour not found');
        if ($this->currentSellerId() !== null && DB::table('products')->where('id', $v->product_id)->value('seller_review') !== 'pending') {
            return CommonHelper::responseError('Stock of approved products is counted by Lebazone when your items arrive');
        }
        $qty = (int) $request->input('qty', 1);
        $row = ProductVariantStoreStock::where('product_variant_id', $v->id)->where('store_id', $shop->id)->first();
        if (!$row) return CommonHelper::responseError('This colour has no stock record in the store');
        $row->available = $request->input('mode') === 'set' ? max(0, $qty) : max(0, (int) $row->available + $qty);
        $row->stock_status = $row->available > 0 ? 1 : (int) $row->stock_status;
        $row->save();
        return CommonHelper::responseSuccessWithData('Stock updated', $this->payload($v->product_id, $v->sku));
    }
}
