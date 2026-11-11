<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): the "does this item have its own barcode?" answer per colour.
//   NULL = not answered, 0 = has a box barcode (scan it from the phone), 1 = no barcode (Lebazone labels)
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('product_variants', 'lbz_no_barcode')) {
            Schema::table('product_variants', fn (Blueprint $t) => $t->boolean('lbz_no_barcode')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_variants', 'lbz_no_barcode')) {
            Schema::table('product_variants', fn (Blueprint $t) => $t->dropColumn('lbz_no_barcode'));
        }
    }
};
