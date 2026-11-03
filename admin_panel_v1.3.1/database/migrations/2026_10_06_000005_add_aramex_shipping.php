<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Custom (Hader): Aramex shipments (label URL per item + settings). */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('order_items', 'shipping_label_url')) {
            Schema::table('order_items', fn (Blueprint $t) => $t->text('shipping_label_url')->nullable());
        }
        $defaults = [
            'aramex_enabled' => '0', 'aramex_mode' => 'test',
            'aramex_username' => '', 'aramex_password' => '', 'aramex_account_number' => '',
            'aramex_account_pin' => '', 'aramex_account_entity' => 'BEY', 'aramex_account_country' => 'LB',
            'aramex_shipper_name' => 'Hader', 'aramex_shipper_phone' => '', 'aramex_shipper_email' => '',
            'aramex_shipper_address' => '', 'aramex_shipper_city' => 'Beirut', 'aramex_shipper_country' => 'LB',
            'aramex_default_weight' => '0.5',
        ];
        foreach ($defaults as $k => $v) {
            if (!DB::table('settings')->where('variable', $k)->exists()) {
                DB::table('settings')->insert(['variable' => $k, 'value' => $v]); // settings has no timestamps
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'shipping_label_url')) {
            Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('shipping_label_url'));
        }
    }
};
