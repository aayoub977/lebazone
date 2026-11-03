<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): the regions (governorates) each driver delivers to. Empty = all.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('delivery_boys', 'warehouse_regions')) {
            Schema::table('delivery_boys', fn (Blueprint $t) => $t->text('warehouse_regions')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('delivery_boys', 'warehouse_regions')) {
            Schema::table('delivery_boys', fn (Blueprint $t) => $t->dropColumn('warehouse_regions'));
        }
    }
};
