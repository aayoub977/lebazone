<?php

namespace App\Http\Controllers\API\Customer;

use App\Helpers\CommonHelper;
use App\Helpers\CustomerProductShaper;
use App\Http\Controllers\Controller;
use App\Models\FlashSale;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Custom (Hader): the live flash deal for the shopper's zone. */
class FlashSaleApiController extends Controller
{
    public function current(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latitude'  => 'required',
            'longitude' => 'required',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $sale = FlashSale::with('items')->where('state', 'live')->where('status', 1)
            ->where('ends_at', '>', now())->orderBy('ends_at')->first();
        if (!$sale) {
            return CommonHelper::responseWithData(null);
        }

        $channel = strtolower(trim((string) $request->header('channel')));
        $channel = in_array($channel, ['quick', 'ecommerce'], true) ? $channel : 'ecommerce';
        $lat = (float) $request->latitude;
        $lng = (float) $request->longitude;
        $zone = CommonHelper::getDeliverableCity($lat, $lng, $channel);
        if (!$zone) {
            return CommonHelper::responseWithData(null);
        }

        $storeIds = Store::where('status', 1)->where('zone_id', $zone->id)->get()
            ->filter(fn ($s) => $channel !== 'quick' || CommonHelper::isStoreOpenNow($s))
            ->pluck('id')->all();

        $delivery = CustomerProductShaper::zoneDelivery($zone, $lat, $lng);
        $userId = $request->user('api-customers')?->id;
        $ctx = [
            'storeIds'      => $storeIds,
            'channel'       => $channel,
            'user_id'       => $userId,
            'storeName'     => $delivery['store_name'],
            'timeToDeliver' => $channel === 'quick' ? (int) $delivery['time_to_deliver'] : 0,
            'currency'      => CommonHelper::countryCurrency($zone?->country),
        ];

        $productIds = $sale->items->map(fn ($i) => (int) optional($i->variant)->product_id)
            ->filter()->unique()->values()->all();
        $block = app(CartApiController::class)->buildRecommendationBlock($productIds, 50, 0, $ctx);
        if (empty($block['products'])) {
            return CommonHelper::responseWithData(null);
        }

        $lang = strtolower((string) $request->header('lang', $request->header('Accept-Language', '')));
        return CommonHelper::responseWithData([
            'id'          => $sale->id,
            'title'       => (str_starts_with($lang, 'ar') && $sale->title_ar) ? $sale->title_ar : $sale->title,
            'starts_at'   => $sale->starts_at->toIso8601String(),
            'ends_at'     => $sale->ends_at->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'products'    => $block['products'],
        ]);
    }
}
