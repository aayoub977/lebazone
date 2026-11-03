<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Custom (Hader): one row per Whish / Areeba payment attempt. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('local_payment_sessions')) {
            Schema::create('local_payment_sessions', function (Blueprint $t) {
                $t->id();
                $t->uuid('token')->unique();
                $t->string('gateway', 20);                  // whish | areeba
                $t->string('type', 10);                     // order | wallet
                $t->string('custom_id');                    // order id, or wallet_recharge-{user}{-cN}
                $t->unsignedBigInteger('user_id')->index();
                $t->decimal('amount', 12, 2);
                $t->string('currency', 3);
                $t->string('request_from', 20)->nullable(); // website | app
                $t->string('status', 10)->default('pending');
                $t->string('gateway_ref')->nullable();      // areeba session id / whish collect id
                $t->string('success_indicator')->nullable();
                $t->json('meta')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('local_payment_sessions');
    }
};
