<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): when the order entered its current warehouse stage, and which stage
// was last reported as overdue (so the "stuck order" alert fires once per stage).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_fulfillments', function (Blueprint $t) {
            if (!Schema::hasColumn('order_fulfillments', 'stage_at')) $t->timestamp('stage_at')->nullable()->after('stage');
            if (!Schema::hasColumn('order_fulfillments', 'alerted_stage')) $t->string('alerted_stage', 20)->nullable()->after('stage_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_fulfillments', function (Blueprint $t) {
            $t->dropColumn(['stage_at', 'alerted_stage']);
        });
    }
};
