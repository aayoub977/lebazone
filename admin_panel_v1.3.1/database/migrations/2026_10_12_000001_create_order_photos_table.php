<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): proof photos taken by the driver - boxes when collected, and at delivery.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('order_photos')) return;
        Schema::create('order_photos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id')->index();
            $t->string('type', 12);            // collected | delivered
            $t->string('path');
            $t->unsignedBigInteger('delivery_boy_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_photos');
    }
};
