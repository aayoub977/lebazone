<?php

namespace App\Console\Commands;

use App\Http\Controllers\API\WarehouseApiController;
use App\Models\Admin;
use App\Notifications\WarehouseAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Custom (Lebazone): alert admins when an order sits too long in a warehouse stage. */
class WarehouseCheckStuck extends Command
{
    protected $signature = 'warehouse:check-stuck';
    protected $description = 'Alert admins about orders stuck in a warehouse stage';

    public function handle(): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('order_fulfillments', 'stage_at')) return 0;
        $limits = WarehouseApiController::limits();
        $admins = Admin::whereIn('role_id', [1, 2])->where('status', 1)->get();
        $n = 0;
        foreach (DB::table('order_fulfillments')->where('stage', '!=', 'out')->get() as $f) {
            $limit = (int) ($limits[$f->stage] ?? 0);
            if ($limit <= 0 || $f->alerted_stage === $f->stage) continue;
            $since = $f->stage_at ?? $f->updated_at;
            $mins = (int) floor((time() - strtotime((string) $since)) / 60);
            if ($mins < $limit) continue;
            $order = DB::table('orders')->where('id', $f->order_id)->first();
            if (!$order || in_array((int) $order->active_status, [1, 6, 7, 8], true)) continue;
            $who = $f->staff_id ? DB::table('warehouse_staff')->where('id', $f->staff_id)->value('name') : null;
            $text = "Warehouse: order {$order->order_number} stuck at \"" . WarehouseApiController::stageLabel($f->stage) . "\" for {$mins} min" . ($who ? " ({$who})" : ' (not assigned)');
            foreach ($admins as $a) {
                try { $a->notify(new WarehouseAlert((int) $order->id, $text)); } catch (\Throwable $e) {}
            }
            DB::table('order_fulfillments')->where('id', $f->id)->update(['alerted_stage' => $f->stage]);
            DB::table('order_fulfillment_logs')->insert(['order_id' => $f->order_id, 'stage' => 'alert', 'actor' => 'system', 'note' => $text, 'created_at' => now()]);
            $n++;
        }
        $this->info("$n alert(s) sent");
        return 0;
    }
}
