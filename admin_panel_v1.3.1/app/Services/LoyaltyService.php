<?php

namespace App\Services;

use App\Helpers\CommonHelper;
use App\Models\OrderStatusList;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Custom (Hader): loyalty points.
 *
 * Earn: once an order is delivered and its longest return window has passed with
 * nothing returned/cancelled, the customer gets floor((final_total − delivery) ×
 * loyalty_points_per_unit) points. Redeem: points convert to wallet balance
 * (loyalty_point_value each), which checkout already accepts.
 */
class LoyaltyService
{
    public function enabled(): bool
    {
        return Setting::get_value('loyalty_enabled') === '1';
    }

    public function config(): array
    {
        return [
            'enabled'        => $this->enabled(),
            'points_per_unit'=> (float) (Setting::get_value('loyalty_points_per_unit') ?: 0),
            'point_value'    => (float) (Setting::get_value('loyalty_point_value') ?: 0),
            'min_redeem'     => (int) (Setting::get_value('loyalty_min_redeem_points') ?: 0),
        ];
    }

    /** Hourly: award points for orders whose return window has closed. */
    public function awardDue(): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $cfg = $this->config();
        $count = 0;
        $delivered = OrderStatusList::$delivered;

        DB::table('orders')
            ->where('active_status', (string) $delivered)
            ->whereNull('loyalty_processed_at')
            ->orderBy('id')
            ->chunkById(200, function ($orders) use ($cfg, $delivered, &$count) {
                foreach ($orders as $o) {
                    $deliveredAt = DB::table('order_statuses')->where('order_id', (string) $o->id)
                        ->where('status', (string) $delivered)->min('created_at');
                    $deliveredAt = $deliveredAt ? \Carbon\Carbon::parse($deliveredAt) : \Carbon\Carbon::parse($o->updated_at);

                    $maxReturnDays = (int) DB::table('order_items')->where('order_id', $o->id)
                        ->where('active_status', '!=', (string) OrderStatusList::$cancelled)
                        ->where('return_status', 1)->max('return_days');
                    if ($deliveredAt->copy()->addDays($maxReturnDays)->isFuture()) {
                        continue; // still returnable — check again later
                    }

                    $forfeit = DB::table('order_items')->where('order_id', $o->id)
                            ->whereIn('active_status', [(string) OrderStatusList::$returned, (string) OrderStatusList::$cancelled])->exists()
                        || DB::table('return_requests')->where('order_id', $o->id)->exists();

                    $base = max(0, (float) ($o->final_total ?? $o->total) - (float) $o->delivery_charge);
                    $points = $forfeit ? 0 : (int) floor($base * $cfg['points_per_unit']);

                    DB::transaction(function () use ($o, $points) {
                        if ($points > 0) {
                            DB::table('users')->where('id', $o->user_id)->increment('loyalty_points', $points);
                            DB::table('loyalty_point_transactions')->insert([
                                'user_id' => $o->user_id, 'order_id' => $o->id, 'points' => $points,
                                'type' => 'earn', 'note' => 'Order #' . ($o->order_number ?? $o->id),
                                'created_at' => now(), 'updated_at' => now(),
                            ]);
                        }
                        DB::table('orders')->where('id', $o->id)
                            ->update(['loyalty_points_awarded' => $points, 'loyalty_processed_at' => now()]);
                    });
                    $count += $points > 0 ? 1 : 0;
                }
            });
        return $count;
    }

    /** Convert points to wallet balance. Returns [ok, message, walletAmount]. */
    public function redeem(User $user, int $points): array
    {
        $cfg = $this->config();
        if (!$cfg['enabled']) {
            return [false, 'Loyalty points are not available right now.', 0];
        }
        if ($points < max(1, $cfg['min_redeem'])) {
            return [false, "You need at least {$cfg['min_redeem']} points to redeem.", 0];
        }

        return DB::transaction(function () use ($user, $points, $cfg) {
            $fresh = User::whereKey($user->id)->lockForUpdate()->first();
            if ((int) $fresh->loyalty_points < $points) {
                return [false, 'You don\'t have enough points.', 0];
            }
            $amount = round($points * $cfg['point_value'], 2);
            if ($amount <= 0) {
                return [false, 'Points have no value configured.', 0];
            }
            $fresh->decrement('loyalty_points', $points);
            CommonHelper::updateUserWalletBalance((float) $fresh->balance + $amount, $fresh->id);
            WalletTransaction::insert([
                'user_id' => $fresh->id, 'type' => 'credit', 'amount' => $amount,
                'message' => "Loyalty points redeemed ({$points} pts)", 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('loyalty_point_transactions')->insert([
                'user_id' => $fresh->id, 'points' => -$points, 'type' => 'redeem', 'wallet_amount' => $amount,
                'note' => 'Converted to wallet', 'created_at' => now(), 'updated_at' => now(),
            ]);
            return [true, 'Points converted to wallet balance.', $amount];
        });
    }
}
