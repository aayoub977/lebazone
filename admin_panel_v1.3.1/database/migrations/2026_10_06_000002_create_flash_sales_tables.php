<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Custom (Hader): time-boxed flash deals with per-variant deal prices. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('flash_sales')) {
            Schema::create('flash_sales', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('title_ar')->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->tinyInteger('status')->default(1)->comment('1 = enabled, 0 = disabled');
                $table->string('state', 12)->default('scheduled')->comment('scheduled | live | ended');
                $table->timestamps();
                $table->index(['state', 'starts_at', 'ends_at']);
            });
        }
        if (!Schema::hasTable('flash_sale_items')) {
            Schema::create('flash_sale_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('flash_sale_id');
                $table->unsignedBigInteger('product_variant_id');
                $table->decimal('deal_price', 12, 2);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['flash_sale_id', 'product_variant_id'], 'fsi_sale_variant_unique');
                $table->foreign('flash_sale_id')->references('id')->on('flash_sales')->onDelete('cascade');
            });
        }
        // Original store sale prices, saved while a deal is live so they can be restored.
        if (!Schema::hasTable('flash_sale_price_backups')) {
            Schema::create('flash_sale_price_backups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('flash_sale_id');
                $table->unsignedBigInteger('pvss_id');
                $table->decimal('original_discounted_price', 12, 2)->nullable();
                $table->decimal('applied_price', 12, 2);
                $table->timestamps();
                $table->unique(['flash_sale_id', 'pvss_id'], 'fspb_sale_pvss_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_price_backups');
        Schema::dropIfExists('flash_sale_items');
        Schema::dropIfExists('flash_sales');
    }
};
