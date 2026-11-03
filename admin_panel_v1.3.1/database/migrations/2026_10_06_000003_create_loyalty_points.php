<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom (Hader): loyalty points (earn on delivered orders after the return window,
 * convert to wallet balance) + SMS/WhatsApp templates for cart reminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'loyalty_points')) {
            Schema::table('users', fn (Blueprint $t) => $t->integer('loyalty_points')->default(0));
        }
        if (!Schema::hasColumn('orders', 'loyalty_points_awarded')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->integer('loyalty_points_awarded')->default(0);
                $t->timestamp('loyalty_processed_at')->nullable()->index();
            });
        }
        if (!Schema::hasTable('loyalty_point_transactions')) {
            Schema::create('loyalty_point_transactions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedBigInteger('order_id')->nullable()->index();
                $t->integer('points')->comment('+ earned, - redeemed');
                $t->string('type', 12)->comment('earn | redeem | adjust');
                $t->decimal('wallet_amount', 12, 2)->default(0);
                $t->string('note')->nullable();
                $t->timestamps();
            });
        }

        $defaults = [
            'loyalty_enabled'            => '1',
            'loyalty_points_per_unit'    => '10',   // points per 1 unit of main currency spent
            'loyalty_point_value'        => '0.01', // wallet value of 1 point (1,000 pts = $10)
            'loyalty_min_redeem_points'  => '500',
        ];
        foreach ($defaults as $k => $v) {
            if (!DB::table('settings')->where('variable', $k)->exists()) {
                DB::table('settings')->insert(['variable' => $k, 'value' => $v]); // settings has no timestamps
            }
        }

        // Cart reminder SMS/WhatsApp templates were missing upstream (push/email only).
        $tpls = [
            'cart_reminder_first_customer'    => 'Hi! {{product_name}} is still in your {{app_name}} cart. Complete your order before it sells out.',
            'cart_reminder_interval_customer' => 'Reminder: {{product_name}} is waiting in your {{app_name}} cart. Order now and don\'t miss out!',
        ];
        $defaultLangId = DB::table('languages')->where('system_type', 4)->where('is_default', 1)->value('id');
        foreach ($tpls as $type => $msg) {
            DB::table('sms_templates')->updateOrInsert(['type' => $type], [
                'message' => $msg, 'placeholders' => json_encode(['product_name', 'app_name']),
                'audience' => 'customer', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $id = DB::table('sms_templates')->where('type', $type)->value('id');
            if ($defaultLangId && $id) {
                DB::table('sms_template_translations')->updateOrInsert(
                    ['sms_template_id' => $id, 'language_id' => $defaultLangId],
                    ['message' => $msg, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
        // Upstream placeholder push title ("Title for product …").
        if (Schema::hasTable('notification_templates')) {
            DB::table('notification_templates')->where('type', 'cart_reminder_interval_customer')
                ->where('title', 'like', 'Title for product%')
                ->update(['title' => 'Still thinking about {{product_name}}?']);
            if (Schema::hasTable('notification_template_translations')) {
                $nid = DB::table('notification_templates')->where('type', 'cart_reminder_interval_customer')->value('id');
                DB::table('notification_template_translations')->where('notification_template_id', $nid)
                    ->where('title', 'like', 'Title for product%')
                    ->update(['title' => 'Still thinking about {{product_name}}?']);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_transactions');
        if (Schema::hasColumn('orders', 'loyalty_points_awarded')) {
            Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['loyalty_points_awarded', 'loyalty_processed_at']));
        }
        if (Schema::hasColumn('users', 'loyalty_points')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('loyalty_points'));
        }
    }
};
