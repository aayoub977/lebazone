<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): employee logins.
//  - permissions: warehouse_pack (Pack orders screen), product_scan (Scan products screen)
//  - roles: Packer, Product entry, Packer + products (each sees only its own screens)
//  - warehouse_staff gets a link to its login (admin_id) and what the employee may do.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouse_staff', function (Blueprint $t) {
            if (!Schema::hasColumn('warehouse_staff', 'admin_id')) $t->unsignedBigInteger('admin_id')->nullable()->after('code');
            if (!Schema::hasColumn('warehouse_staff', 'can_pack')) $t->tinyInteger('can_pack')->default(1)->after('admin_id');
            if (!Schema::hasColumn('warehouse_staff', 'can_products')) $t->tinyInteger('can_products')->default(0)->after('can_pack');
        });

        $now = now();
        $cat = DB::table('permission_categories')->where('name', 'warehouse')->value('id')
            ?: DB::table('permission_categories')->insertGetId(['name' => 'warehouse', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $perm = [];
        foreach (['warehouse_pack', 'product_scan'] as $p) {
            $perm[$p] = DB::table('permissions')->where('name', $p)->where('guard_name', 'web')->value('id')
                ?: DB::table('permissions')->insertGetId(['name' => $p, 'guard_name' => 'web', 'category_id' => $cat, 'created_at' => $now, 'updated_at' => $now]);
        }
        $give = function (int $roleId, array $names) use ($perm) {
            foreach ($names as $n) {
                $pid = $perm[$n] ?? DB::table('permissions')->where('name', $n)->where('guard_name', 'web')->value('id');
                if ($pid && !DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $pid)->exists()) {
                    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $pid]);
                }
            }
        };
        // Admins keep everything.
        foreach (DB::table('roles')->whereIn('name', ['Super Admin', 'Admin'])->pluck('id') as $rid) $give((int) $rid, ['warehouse_pack', 'product_scan']);

        $roles = [
            'Packer' => ['warehouse_pack'],
            'Product entry' => ['product_scan'],
            'Packer + products' => ['warehouse_pack', 'product_scan'],
        ];
        foreach ($roles as $name => $perms) {
            $q = DB::table('roles')->where('name', $name)->where('guard_name', 'web');
            if (Schema::hasColumn('roles', 'store_id')) $q->whereNull('store_id');
            $rid = $q->value('id') ?: DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            $give((int) $rid, $perms);
        }
        try { app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions(); } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        Schema::table('warehouse_staff', function (Blueprint $t) {
            foreach (['admin_id', 'can_pack', 'can_products'] as $c) if (Schema::hasColumn('warehouse_staff', $c)) $t->dropColumn($c);
        });
    }
};
