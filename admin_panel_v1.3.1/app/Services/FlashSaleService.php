<?php

namespace App\Services;

use App\Models\FlashSale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Custom (Hader): starts and ends flash deals.
 *
 * While a deal is live the deal price is written into each store's
 * product_variant_store_stocks.discounted_price, so the whole existing pricing
 * pipeline (cards, cart, checkout, tax, invoices, reports) just works. The
 * original sale price is saved in flash_sale_price_backups and put back when
 * the deal ends, is disabled, or is deleted — unless an admin changed the
 * price in the meantime, in which case the admin's newer price is kept.
 */
class FlashSaleService
{
    /** Run every minute: start due deals, end finished/disabled ones. */
    public function sync(): array
    {
        $now = now();
        $started = $ended = 0;

        FlashSale::where('state', 'live')
            ->where(fn ($q) => $q->where('ends_at', '<=', $now)->orWhere('status', 0))
            ->get()->each(function (FlashSale $sale) use (&$ended) {
                $this->end($sale);
                $ended++;
            });

        FlashSale::where('state', 'scheduled')->where('status', 1)
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
            ->get()->each(function (FlashSale $sale) use (&$started) {
                $this->start($sale);
                $started++;
            });

        // Disabled/expired scheduled deals never go live.
        FlashSale::where('state', 'scheduled')->where('ends_at', '<=', $now)->update(['state' => 'ended']);

        return compact('started', 'ended');
    }

    public function start(FlashSale $sale): void
    {
        DB::transaction(function () use ($sale) {
            foreach ($sale->items as $item) {
                $rows = DB::table('product_variant_store_stocks')
                    ->where('product_variant_id', $item->product_variant_id)
                    ->lockForUpdate()->get(['id', 'price', 'discounted_price']);
                foreach ($rows as $row) {
                    $price = (float) $row->price;
                    $deal  = round((float) $item->deal_price, 2);
                    if ($price <= 0 || $deal <= 0 || $deal >= $price) {
                        continue; // deal must be below the regular price
                    }
                    DB::table('flash_sale_price_backups')->updateOrInsert(
                        ['flash_sale_id' => $sale->id, 'pvss_id' => $row->id],
                        ['original_discounted_price' => $row->discounted_price, 'applied_price' => $deal,
                         'created_at' => now(), 'updated_at' => now()]
                    );
                    DB::table('product_variant_store_stocks')->where('id', $row->id)
                        ->update(['discounted_price' => $deal, 'updated_at' => now()]);
                }
            }
            $sale->forceFill(['state' => 'live'])->save();
        });
        Log::info("Flash sale #{$sale->id} started");
    }

    public function end(FlashSale $sale, string $newState = 'ended'): void
    {
        DB::transaction(function () use ($sale, $newState) {
            $backups = DB::table('flash_sale_price_backups')->where('flash_sale_id', $sale->id)->get();
            foreach ($backups as $b) {
                // Only restore if nobody changed the price while the deal was live.
                DB::table('product_variant_store_stocks')
                    ->where('id', $b->pvss_id)
                    ->where('discounted_price', $b->applied_price)
                    ->update(['discounted_price' => $b->original_discounted_price, 'updated_at' => now()]);
            }
            DB::table('flash_sale_price_backups')->where('flash_sale_id', $sale->id)->delete();
            $sale->forceFill(['state' => $newState])->save();
        });
        Log::info("Flash sale #{$sale->id} ended");
    }
}
