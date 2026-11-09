<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): seller store pages - a banner and a short "about" each seller sets
// from their login; customers reach the page from "Sold by" on a product.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('sellers')) return;
        Schema::table('sellers', function (Blueprint $t) {
            if (!Schema::hasColumn('sellers', 'store_banner')) $t->string('store_banner')->nullable();
            if (!Schema::hasColumn('sellers', 'store_about')) $t->text('store_about')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sellers')) return;
        Schema::table('sellers', function (Blueprint $t) {
            foreach (['store_banner', 'store_about'] as $c) if (Schema::hasColumn('sellers', $c)) $t->dropColumn($c);
        });
    }
};
