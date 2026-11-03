<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom (Lebazone): Purchasing — the "Orders Online" import-costing tool inside the admin panel.
 * Items bought from suppliers abroad, with box / item sizes, CBM, freight rates by origin,
 * shipments that share one delivery cost, and the landed cost per piece. Items can be linked to
 * Lebazone products so the landed cost becomes the product's purchase price and received
 * pieces are added to stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pur_settings')) {
            Schema::create('pur_settings', function (Blueprint $t) {
                $t->string('key', 60)->primary();
                $t->text('value')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('pur_suppliers')) {
            Schema::create('pur_suppliers', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('company')->nullable();
                $t->string('platform', 60)->nullable();
                $t->string('contact')->nullable();
                $t->string('phone', 60)->nullable();
                $t->string('whatsapp', 60)->nullable();
                $t->string('wechat', 80)->nullable();
                $t->string('email')->nullable();
                $t->string('website', 500)->nullable();
                $t->string('address', 500)->nullable();
                $t->string('city', 100)->nullable();
                $t->string('country', 100)->nullable()->comment('Origin country — picks the automatic freight rate');
                $t->string('payment_terms')->nullable();
                $t->decimal('lead_time', 8, 1)->nullable();
                $t->string('moq', 100)->nullable();
                $t->string('bank', 500)->nullable();
                $t->unsignedTinyInteger('rating')->nullable();
                $t->text('notes')->nullable();
                $t->string('source_id', 64)->nullable()->index()->comment('id in the old Orders Online app');
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('pur_rates')) {
            Schema::create('pur_rates', function (Blueprint $t) {
                $t->id();
                $t->string('name')->nullable();
                $t->string('country', 100)->nullable();
                $t->string('mode', 40)->nullable()->comment('Sea (LCL), Sea (FCL), Air, Land, Express');
                $t->decimal('price', 12, 2)->default(0)->comment('per 1 CBM');
                $t->boolean('auto')->default(false)->comment('used automatically for suppliers from this country');
                $t->text('notes')->nullable();
                $t->json('history')->nullable()->comment('[{price, until}] previous prices');
                $t->string('source_id', 64)->nullable()->index();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('pur_shipments')) {
            Schema::create('pur_shipments', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('status', 30)->default('Planning');
                $t->date('ship_date')->nullable();
                $t->date('eta')->nullable();
                $t->string('forwarder')->nullable();
                $t->string('tracking')->nullable();
                $t->string('rate_mode', 10)->default('')->comment("'' per item | rate | custom");
                $t->unsignedBigInteger('rate_id')->nullable();
                $t->decimal('cbm_price', 12, 2)->nullable();
                $t->decimal('delivery_cost', 12, 2)->default(0)->comment('one amount for the whole shipment');
                $t->string('allocation', 10)->default('cbm')->comment('cbm | items | value | equal');
                $t->text('notes')->nullable();
                $t->timestamp('received_at')->nullable();
                $t->string('source_id', 64)->nullable()->index();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('pur_items')) {
            Schema::create('pur_items', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('sku', 100)->nullable();
                $t->string('variants', 500)->nullable();
                $t->string('picture', 500)->nullable()->comment('path under storage/app/public');
                $t->unsignedBigInteger('supplier_id')->nullable()->index();
                $t->unsignedBigInteger('shipment_id')->nullable()->index();
                $t->string('status', 20)->default('Quote');
                $t->decimal('qty', 12, 2)->nullable()->comment('order quantity (pieces)');
                $t->decimal('boxes', 12, 3)->nullable();
                $t->decimal('per_box', 12, 2)->nullable();
                $t->decimal('box_l', 10, 2)->nullable();
                $t->decimal('box_w', 10, 2)->nullable();
                $t->decimal('box_h', 10, 2)->nullable();
                $t->decimal('item_l', 10, 2)->nullable();
                $t->decimal('item_w', 10, 2)->nullable();
                $t->decimal('item_h', 10, 2)->nullable();
                $t->string('cbm_basis', 10)->default('')->comment("'' automatic | box | item");
                $t->decimal('unit_cost', 12, 4)->nullable();
                $t->decimal('sell_price', 12, 2)->nullable();
                $t->decimal('target_margin', 6, 2)->nullable()->comment('% — overrides the default margin');
                $t->decimal('delivery', 12, 2)->nullable()->comment('used only when not in a shipment');
                $t->string('rate_mode', 10)->default('')->comment("'' automatic | rate | custom");
                $t->unsignedBigInteger('rate_id')->nullable();
                $t->decimal('cbm_custom', 12, 2)->nullable();
                $t->text('notes')->nullable();
                // Link to the Lebazone catalogue
                $t->unsignedBigInteger('product_id')->nullable()->index();
                $t->unsignedBigInteger('product_variant_id')->nullable()->index();
                $t->unsignedBigInteger('store_id')->nullable();
                $t->decimal('received_qty', 12, 2)->default(0);
                $t->timestamp('received_at')->nullable();
                $t->timestamp('cost_applied_at')->nullable();
                $t->decimal('cost_applied', 12, 4)->nullable();
                $t->string('source_id', 64)->nullable()->index();
                $t->timestamps();
            });
        }

        // Permission "purchasing" for the admin menu / screens; Super Admin and Admin get it.
        $now = now();
        $cat = DB::table('permission_categories')->where('name', 'purchasing')->value('id')
            ?: DB::table('permission_categories')->insertGetId(['name' => 'purchasing', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $pid = DB::table('permissions')->where('name', 'purchasing')->where('guard_name', 'web')->value('id')
            ?: DB::table('permissions')->insertGetId(['name' => 'purchasing', 'guard_name' => 'web', 'category_id' => $cat, 'created_at' => $now, 'updated_at' => $now]);
        foreach (DB::table('roles')->whereIn('name', ['Super Admin', 'Admin'])->pluck('id') as $rid) {
            if (!DB::table('role_has_permissions')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                DB::table('role_has_permissions')->insert(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }
        try { app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions(); } catch (\Throwable $e) {}

        foreach (['cbm_price' => '380', 'currency' => 'USD', 'carton_pad' => '3', 'default_margin' => '35'] as $k => $v) {
            if (!DB::table('pur_settings')->where('key', $k)->exists()) {
                DB::table('pur_settings')->insert(['key' => $k, 'value' => $v, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        foreach (['pur_items', 'pur_shipments', 'pur_rates', 'pur_suppliers', 'pur_settings'] as $t) Schema::dropIfExists($t);
    }
};
