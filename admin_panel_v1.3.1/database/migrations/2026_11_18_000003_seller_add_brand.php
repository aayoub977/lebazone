<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): sellers may add a new brand from the product form ("+ Add brand").
return new class extends Migration {
    public function up(): void
    {
        $q = DB::table('roles')->where('name', 'Seller')->where('guard_name', 'web');
        if (Schema::hasColumn('roles', 'store_id')) $q->whereNull('store_id');
        $rid = $q->value('id');
        $pid = DB::table('permissions')->where('name', 'brand_create')->where('guard_name', 'web')->value('id');
        if ($rid && $pid && !DB::table('role_has_permissions')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
            DB::table('role_has_permissions')->insert(['role_id' => $rid, 'permission_id' => $pid]);
        }
        try { app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions(); } catch (\Throwable $e) {}
    }

    public function down(): void {}
};
