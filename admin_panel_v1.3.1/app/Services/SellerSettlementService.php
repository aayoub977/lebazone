<?php

namespace App\Services;

use App\Models\OrderStatusList;
use Illuminate\Support\Facades\DB;

/**
 * Custom (Hader): credits consignment sellers for delivered items once the
 * item's return window has passed (returned/cancelled items earn nothing).
 */
class SellerSettlementService
{
    public function settleDue(): int
    {
        $delivered = (string) OrderStatusList::$delivered;
        $done = 0;

        DB::table('order_items as oi')
            ->join('product_variants as pv', 'pv.id', '=', 'oi.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('sellers as s', 's.id', '=', 'p.seller_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereNull('oi.seller_processed_at')
            ->whereIn('oi.active_status', [$delivered, (string) OrderStatusList::$cancelled, (string) OrderStatusList::$returned])
            ->select('oi.*', 's.id as seller_id', 's.commission_percent', 'o.currency_code as order_currency')
            ->orderBy('oi.id')
            ->chunk(200, function ($rows) use ($delivered, &$done) {
                foreach ($rows as $it) {
                    if ((string) $it->active_status !== $delivered) {
                        DB::table('order_items')->where('id', $it->id)->update(['seller_processed_at' => now()]);
                        continue; // cancelled / returned → nothing owed
                    }
                    $deliveredAt = DB::table('order_statuses')
                        ->where('order_id', (string) $it->order_id)
                        ->where(fn ($q) => $q->where('order_item_id', $it->id)->orWhereNull('order_item_id'))
                        ->where('status', $delivered)->min('created_at');
                    $deliveredAt = $deliveredAt ? \Carbon\Carbon::parse($deliveredAt) : \Carbon\Carbon::parse($it->updated_at);
                    $returnDays = ((int) $it->return_status === 1) ? (int) $it->return_days : 0;
                    if ($deliveredAt->copy()->addDays($returnDays)->isFuture()) {
                        continue;
                    }
                    if (DB::table('return_requests')->where('order_item_id', $it->id)->exists()) {
                        DB::table('order_items')->where('id', $it->id)->update(['seller_processed_at' => now()]);
                        continue;
                    }
                    $gross = round((float) $it->sub_total, 2);
                    $commission = round($gross * (float) $it->commission_percent / 100, 2);
                    DB::transaction(function () use ($it, $gross, $commission) {
                        DB::table('seller_ledger')->insertOrIgnore([
                            'seller_id' => $it->seller_id, 'order_id' => $it->order_id, 'order_item_id' => $it->id,
                            'type' => 'sale', 'gross' => $gross, 'commission' => $commission, 'net' => $gross - $commission,
                            'currency_code' => $it->order_currency, 'note' => mb_substr((string) $it->product_name, 0, 180) . ' ×' . $it->quantity,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        DB::table('order_items')->where('id', $it->id)->update(['seller_processed_at' => now()]);
                    });
                    $done++;
                }
            });
        return $done;
    }
}
