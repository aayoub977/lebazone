<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): item weight in grams - with the "cost per kg" setting it gives the item's cost.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'lbz_weight_g')) {
            Schema::table('products', fn (Blueprint $t) => $t->unsignedInteger('lbz_weight_g')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'lbz_weight_g')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('lbz_weight_g'));
        }
    }
};
