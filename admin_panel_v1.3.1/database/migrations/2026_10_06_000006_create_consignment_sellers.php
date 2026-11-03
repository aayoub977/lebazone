<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom (Hader): consignment sellers — suppliers whose products Hader stocks,
 * sells and delivers. Sellers earn (sale − commission) once the return window
 * closes; payouts are recorded against a running ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sellers')) {
            Schema::create('sellers', function (Blueprint $t) {
                $t->id();
                $t->string('business_name');
                $t->string('contact_name');
                $t->string('email')->nullable();
                $t->string('phone');
                $t->string('city')->nullable();
                $t->string('category')->nullable();
                $t->text('message')->nullable();
                $t->decimal('commission_percent', 5, 2)->default(15);
                $t->string('status', 12)->default('pending')->comment('pending | active | rejected | suspended');
                $t->text('payout_details')->nullable();
                $t->text('admin_notes')->nullable();
                $t->uuid('statement_token')->unique();
                $t->timestamps();
            });
        }
        if (!Schema::hasColumn('products', 'seller_id')) {
            Schema::table('products', fn (Blueprint $t) => $t->unsignedBigInteger('seller_id')->nullable()->index());
        }
        if (!Schema::hasTable('seller_ledger')) {
            Schema::create('seller_ledger', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('seller_id')->index();
                $t->unsignedBigInteger('order_id')->nullable();
                $t->unsignedBigInteger('order_item_id')->nullable()->unique();
                $t->string('type', 10)->comment('sale | payout | adjust');
                $t->decimal('gross', 12, 2)->default(0);
                $t->decimal('commission', 12, 2)->default(0);
                $t->decimal('net', 12, 2)->comment('+ owed to seller, − paid out');
                $t->string('currency_code', 3)->nullable();
                $t->string('note')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasColumn('order_items', 'seller_processed_at')) {
            Schema::table('order_items', fn (Blueprint $t) => $t->timestamp('seller_processed_at')->nullable()->index());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_ledger');
        Schema::dropIfExists('sellers');
        if (Schema::hasColumn('products', 'seller_id')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('seller_id'));
        }
        if (Schema::hasColumn('order_items', 'seller_processed_at')) {
            Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('seller_processed_at'));
        }
    }
};
