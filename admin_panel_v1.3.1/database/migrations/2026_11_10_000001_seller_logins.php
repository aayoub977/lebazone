<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): seller logins.
//  - sellers.admin_id     : the panel login of a consignment seller
//  - products.seller_review: 'pending' (added by a seller, hidden until Lebazone approves),
//                            'rejected', or NULL (normal / approved product)
//  - permission seller_portal + role "Seller" (Scan products screen + their own "My shop" page)
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sellers') && !Schema::hasColumn('sellers', 'admin_id')) {
            Schema::table('sellers', fn (Blueprint $t) => $t->unsignedBigInteger('admin_id')->nullable()->index());
        }
        if (!Schema::hasColumn('products', 'seller_review')) {
            Schema::table('products', function (Blueprint $t) {
                $t->string('seller_review', 20)->nullable()->index();
                $t->string('seller_review_note', 255)->nullable();
            });
        }

        $now = now();
        $cat = DB::table('permission_categories')->where('name', 'warehouse')->value('id')
            ?: DB::table('permission_categories')->insertGetId(['name' => 'warehouse', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $pid = DB::table('permissions')->where('name', 'seller_portal')->where('guard_name', 'web')->value('id')
            ?: DB::table('permissions')->insertGetId(['name' => 'seller_portal', 'guard_name' => 'web', 'category_id' => $cat, 'created_at' => $now, 'updated_at' => $now]);
        $scan = DB::table('permissions')->where('name', 'product_scan')->where('guard_name', 'web')->value('id');

        $q = DB::table('roles')->where('name', 'Seller')->where('guard_name', 'web');
        if (Schema::hasColumn('roles', 'store_id')) $q->whereNull('store_id');
        $rid = $q->value('id') ?: DB::table('roles')->insertGetId(['name' => 'Seller', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        foreach (array_filter([$pid, $scan]) as $p) {
            if (!DB::table('role_has_permissions')->where('role_id', $rid)->where('permission_id', $p)->exists()) {
                DB::table('role_has_permissions')->insert(['role_id' => $rid, 'permission_id' => $p]);
            }
        }
        try { app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions(); } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        if (Schema::hasColumn('sellers', 'admin_id')) Schema::table('sellers', fn (Blueprint $t) => $t->dropColumn('admin_id'));
        if (Schema::hasColumn('products', 'seller_review')) Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['seller_review', 'seller_review_note']));
    }
};
