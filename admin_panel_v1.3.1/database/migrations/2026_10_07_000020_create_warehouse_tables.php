<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): warehouse order protocol - staff badges, per-order fulfilment
// progress (assign -> pick -> pack -> stamp -> region shelf -> driver) and an audit log.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('warehouse_staff')) {
            Schema::create('warehouse_staff', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('phone')->nullable();
                $t->string('code', 32)->unique();      // badge barcode, e.g. EMP0001
                $t->tinyInteger('active')->default(1);
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('order_fulfillments')) {
            Schema::create('order_fulfillments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('order_id')->unique();
                $t->string('region', 32)->nullable()->index();
                $t->string('stage', 20)->default('new')->index(); // new, assigned, picking, picked, packed, stamped, ready, out
                $t->unsignedBigInteger('staff_id')->nullable();   // assigned employee
                $t->unsignedBigInteger('driver_id')->nullable();  // delivery_boys.id
                $t->unsignedInteger('boxes')->default(0);
                $t->json('picked')->nullable();                   // {order_item_id: qty}
                $t->json('shelved')->nullable();                  // [box numbers on the region shelf]
                $t->json('handed')->nullable();                   // [box numbers given to the driver]
                $t->timestamp('assigned_at')->nullable();
                $t->timestamp('picked_at')->nullable();
                $t->timestamp('packed_at')->nullable();
                $t->timestamp('stamped_at')->nullable();
                $t->timestamp('ready_at')->nullable();
                $t->timestamp('handed_at')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('order_fulfillment_logs')) {
            Schema::create('order_fulfillment_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('order_id')->index();
                $t->string('stage', 20);
                $t->string('actor')->nullable();
                $t->string('note')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillment_logs');
        Schema::dropIfExists('order_fulfillments');
        Schema::dropIfExists('warehouse_staff');
    }
};
