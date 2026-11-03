<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): manufacturer barcode (EAN/UPC) per colour/size. Several colours may share
// one barcode on cheap imports, so it is indexed but not unique.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('product_variants', 'barcode')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->string('barcode', 64)->nullable()->after('sku');
                $table->index('barcode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_variants', 'barcode')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropIndex(['barcode']);
                $table->dropColumn('barcode');
            });
        }
    }
};
