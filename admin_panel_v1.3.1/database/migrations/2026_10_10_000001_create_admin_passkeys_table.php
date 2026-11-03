<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Custom (Lebazone): Face ID / fingerprint sign-in (passkeys) for admins, employees and drivers.
// Only a public key is stored - the face/fingerprint never leaves the phone.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('admin_passkeys')) return;
        Schema::create('admin_passkeys', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_id')->index();
            $t->string('credential_id', 512)->unique();
            $t->text('public_key');
            $t->unsignedBigInteger('sign_count')->default(0);
            $t->string('device_name', 100)->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_passkeys');
    }
};
