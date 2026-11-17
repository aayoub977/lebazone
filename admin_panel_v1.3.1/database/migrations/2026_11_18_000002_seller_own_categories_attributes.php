<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): each seller has their OWN categories (shown on their store page) and
// their OWN attributes (colours, sizes, materials...). Lebazone's categories/attributes stay
// Lebazone's. Every product still sits in one Lebazone category for the main menu.
//   attributes.seller_id          NULL = Lebazone's, else the seller who made it
//   seller_categories             a seller's own categories
//   products.seller_category_id   the product's category on the seller's store page
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('attributes', 'seller_id')) {
            Schema::table('attributes', fn (Blueprint $t) => $t->unsignedBigInteger('seller_id')->nullable()->index());
        }
        if (!Schema::hasTable('seller_categories')) {
            Schema::create('seller_categories', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('seller_id')->index();
                $t->string('name', 100);
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
            });
        }
        if (!Schema::hasColumn('products', 'seller_category_id')) {
            Schema::table('products', fn (Blueprint $t) => $t->unsignedBigInteger('seller_category_id')->nullable()->index());
        }

        // sellers may create / edit their own attributes from the product form
        $q = DB::table('roles')->where('name', 'Seller')->where('guard_name', 'web');
        if (Schema::hasColumn('roles', 'store_id')) $q->whereNull('store_id');
        $rid = $q->value('id');
        if ($rid) {
            foreach (['attribute_create', 'attribute_update'] as $name) {
                $pid = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
                if ($pid && !DB::table('role_has_permissions')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('role_has_permissions')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }
        try { app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions(); } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'seller_category_id')) Schema::table('products', fn (Blueprint $t) => $t->dropColumn('seller_category_id'));
        Schema::dropIfExists('seller_categories');
        if (Schema::hasColumn('attributes', 'seller_id')) Schema::table('attributes', fn (Blueprint $t) => $t->dropColumn('seller_id'));
    }
};
