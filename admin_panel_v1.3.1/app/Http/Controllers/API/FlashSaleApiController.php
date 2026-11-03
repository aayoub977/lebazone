<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\FlashSaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Custom (Hader): admin CRUD for flash deals. */
class FlashSaleApiController extends Controller
{
    public function index()
    {
        $sales = FlashSale::withCount('items')->orderByDesc('starts_at')->get();
        return CommonHelper::responseWithData($sales);
    }

    public function edit($id)
    {
        $sale = FlashSale::with('items')->find($id);
        if (!$sale) {
            return CommonHelper::responseError('Flash deal not found');
        }
        $data = $sale->toArray();
        $data['items'] = $this->describeVariants($sale->items->pluck('deal_price', 'product_variant_id')->all());
        return CommonHelper::responseWithData($data);
    }

    public function save(Request $request, FlashSaleService $service)
    {
        $items = json_decode((string) $request->input('items', '[]'), true) ?: [];
        $validator = Validator::make($request->all() + ['items_array' => $items], [
            'title'                          => 'required|string|max:191',
            'starts_at'                      => 'required|date',
            'ends_at'                        => 'required|date|after:starts_at',
            'items_array'                    => 'required|array|min:1',
            'items_array.*.product_variant_id' => 'required|integer|exists:product_variants,id',
            'items_array.*.deal_price'       => 'required|numeric|min:0.01',
        ], [
            'items_array.required' => 'Add at least one product to the deal.',
            'items_array.min'      => 'Add at least one product to the deal.',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $sale = $request->filled('id') ? FlashSale::find($request->id) : new FlashSale();
        if (!$sale) {
            return CommonHelper::responseError('Flash deal not found');
        }

        // A live deal is ended first so new prices/times apply cleanly, then re-synced.
        if ($sale->exists && $sale->state === 'live') {
            $service->end($sale, 'scheduled');
        }

        DB::transaction(function () use ($sale, $request, $items) {
            $sale->fill([
                'title'     => $request->title,
                'title_ar'  => $request->input('title_ar'),
                'starts_at' => $request->starts_at,
                'ends_at'   => $request->ends_at,
                'status'    => (int) $request->input('status', 1),
            ]);
            if ($sale->state === 'ended' && $sale->ends_at->isFuture()) {
                $sale->state = 'scheduled';
            }
            $sale->state = $sale->state ?: 'scheduled';
            $sale->save();

            FlashSaleItem::where('flash_sale_id', $sale->id)->delete();
            foreach (array_values($items) as $i => $it) {
                FlashSaleItem::create([
                    'flash_sale_id'      => $sale->id,
                    'product_variant_id' => (int) $it['product_variant_id'],
                    'deal_price'         => round((float) $it['deal_price'], 2),
                    'sort_order'         => $i,
                ]);
            }
        });

        $service->sync(); // starts immediately if the window is already open
        return CommonHelper::responseSuccess('Flash deal saved');
    }

    public function delete(Request $request, FlashSaleService $service)
    {
        $sale = FlashSale::find($request->id);
        if (!$sale) {
            return CommonHelper::responseError('Flash deal not found');
        }
        if ($sale->state === 'live') {
            $service->end($sale);
        }
        $sale->delete();
        return CommonHelper::responseSuccess('Flash deal deleted');
    }

    /** Variant picker: search by product name or SKU. */
    public function variants(Request $request)
    {
        $search = trim((string) $request->input('search', ''));
        $productIds = Product::query()
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                      ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$search}%"))
                      ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('id')->limit(30)->pluck('id');

        $variantIds = ProductVariant::whereIn('product_id', $productIds)->pluck('id')->all();
        return CommonHelper::responseWithData($this->describeVariants(array_fill_keys($variantIds, null)));
    }

    /** [variant_id => deal_price|null] → rows with product/variant names and store prices. */
    private function describeVariants(array $dealByVariant): array
    {
        if (empty($dealByVariant)) {
            return [];
        }
        $variants = ProductVariant::with('product.translations')->whereIn('id', array_keys($dealByVariant))->get()->keyBy('id');
        $prices = DB::table('product_variant_store_stocks')
            ->whereIn('product_variant_id', array_keys($dealByVariant))
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, MIN(price) as min_price, MAX(price) as max_price')
            ->get()->keyBy('product_variant_id');

        $rows = [];
        foreach ($dealByVariant as $vid => $deal) {
            $v = $variants[$vid] ?? null;
            if (!$v) {
                continue;
            }
            $rows[] = [
                'product_variant_id' => (int) $vid,
                'product_id'         => (int) $v->product_id,
                'product_name'       => (string) ($v->product->name ?? ''),
                'variant_name'       => (string) ($v->name ?? ''),
                'sku'                => (string) ($v->sku ?? ''),
                'price'              => isset($prices[$vid]) ? (float) $prices[$vid]->min_price : null,
                'max_price'          => isset($prices[$vid]) ? (float) $prices[$vid]->max_price : null,
                'deal_price'         => $deal !== null ? (float) $deal : null,
            ];
        }
        return $rows;
    }
}
