<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): Order Station.
//  - products.lbz_size: S / M / L / XL, used to guess how many boxes an order needs
//  - order_fulfillments: guessed boxes + what was printed
//  - print_jobs: phones and the board send prints here; the Order Station PC prints them
//  - "Order Station" role (permission warehouse_station) for the touch-screen PC login
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'lbz_size')) {
            Schema::table('products', fn (Blueprint $t) => $t->string('lbz_size', 2)->nullable());
        }
        Schema::table('order_fulfillments', function (Blueprint $t) {
            if (!Schema::hasColumn('order_fulfillments', 'boxes_guess')) $t->unsignedInteger('boxes_guess')->nullable();
            if (!Schema::hasColumn('order_fulfillments', 'printed_at')) $t->timestamp('printed_at')->nullable();
            if (!Schema::hasColumn('order_fulfillments', 'printed_boxes')) $t->unsignedInteger('printed_boxes')->default(0);
        });
        if (!Schema::hasTable('print_jobs')) {
            Schema::create('print_jobs', function (Blueprint $t) {
                $t->id();
                $t->string('type', 10);                 // sheet | boxes
                $t->unsignedBigInteger('order_id')->index();
                $t->unsignedInteger('boxes')->default(0);
                $t->string('status', 10)->default('pending')->index(); // pending | done
                $t->string('requested_by')->nullable();
                $t->timestamp('printed_at')->nullable();
                $t->timestamps();
            });
        }

        $now = now();
        $cat = DB::table('permission_categories')->where('name', 'warehouse')->value('id')
            ?: DB::table('permission_categories')->insertGetId(['name' => 'warehouse', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $pid = DB::table('permissions')->where('name', 'warehouse_station')->where('guard_name', 'web')->value('id')
            ?: DB::table('permissions')->insertGetId(['name' => 'warehouse_station', 'guard_name' => 'web', 'category_id' => $cat, 'created_at' => $now, 'updated_at' => $now]);
        $roles = DB::table('roles')->whereIn('name', ['Super Admin', 'Admin'])->pluck('id')->all();
        $q = DB::table('roles')->where('name', 'Order Station')->where('guard_name', 'web');
        if (Schema::hasColumn('roles', 'store_id')) $q->whereNull('store_id');
        $roles[] = $q->value('id') ?: DB::table('roles')->insertGetId(['name' => 'Order Station', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        foreach ($roles as $rid) {
            if (!DB::table('role_has_permissions')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                DB::table('role_has_permissions')->insert(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }
        try { app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions(); } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
