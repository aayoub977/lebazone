<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Services\Shipping\AramexService;
use Illuminate\Http\Request;

/** Custom (Hader): Aramex settings + "book shipment" for order items. */
class AramexApiController extends Controller
{
    private const KEYS = ['aramex_enabled', 'aramex_mode', 'aramex_username', 'aramex_password', 'aramex_account_number',
        'aramex_account_pin', 'aramex_account_entity', 'aramex_account_country', 'aramex_shipper_name',
        'aramex_shipper_phone', 'aramex_shipper_email', 'aramex_shipper_address', 'aramex_shipper_city',
        'aramex_shipper_country', 'aramex_default_weight'];

    public function settings()
    {
        $out = [];
        foreach (self::KEYS as $k) {
            $out[$k] = (string) Setting::get_value($k);
        }
        if (shouldDemoMask()) {
            $out['aramex_password'] = $out['aramex_account_pin'] = '';
        }
        return CommonHelper::responseWithData($out);
    }

    public function saveSettings(Request $request)
    {
        foreach (self::KEYS as $k) {
            if ($request->has($k)) {
                Setting::updateOrCreate(['variable' => $k], ['value' => trim((string) $request->input($k))]);
            }
        }
        return CommonHelper::responseSuccess('Aramex settings saved');
    }

    /** Book one Aramex shipment covering the selected items of ONE order. */
    public function createShipment(Request $request, AramexService $aramex)
    {
        if (!AramexService::enabled()) {
            return CommonHelper::responseError('Aramex is not set up yet (Delivery → Aramex shipping).');
        }
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('order_item_ids')))));
        $items = OrderItem::whereIn('id', $ids)->get();
        if ($items->isEmpty() || $items->pluck('order_id')->unique()->count() !== 1) {
            return CommonHelper::responseError('Select items from a single order.');
        }
        $order = Order::find($items->first()->order_id);
        if (!$order || $order->channel !== 'ecommerce') {
            return CommonHelper::responseError('Aramex shipments are for ecommerce (scheduled delivery) orders.');
        }
        if ($items->contains(fn ($i) => !empty($i->tracking_id) || (int) $i->delivery_boy_id > 0)) {
            return CommonHelper::responseError('Some selected items already have a courier or delivery partner.');
        }

        $r = $aramex->createShipment($order, $items);
        if (!$r['ok']) {
            return CommonHelper::responseError($r['message']);
        }

        // Reuse the vendor's tracking logic (validations, status rules) for every item.
        $save = app(OrdersApiController::class)->saveOrderTrackingItems(new Request([
            'order_item_ids' => implode(',', $items->pluck('id')->all()),
            'courier_agency' => 'Aramex',
            'tracking_id'    => $r['awb'],
            'tracking_url'   => $r['tracking_url'],
        ]));
        OrderItem::whereIn('id', $items->pluck('id'))->update(['shipping_label_url' => $r['label_url'] ?: null]);

        $payload = $save->getData(true);
        if ((int) ($payload['status'] ?? 0) !== 1) {
            return CommonHelper::responseError('Shipment ' . $r['awb'] . ' was booked, but saving tracking failed: ' . ($payload['message'] ?? ''));
        }
        return CommonHelper::responseSuccessWithData('Aramex shipment ' . $r['awb'] . ' booked', $r);
    }
}
