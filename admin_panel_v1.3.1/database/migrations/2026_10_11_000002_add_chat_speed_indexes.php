<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Lebazone: keeps unread counts and the chat popup poll fast as message history grows.
return new class extends Migration {
    private function has(string $name): bool
    {
        return collect(DB::select('SHOW INDEX FROM messages'))->contains(fn ($i) => $i->Key_name === $name);
    }

    public function up(): void
    {
        if (!Schema::hasTable('messages')) return;
        Schema::table('messages', function (Blueprint $t) {
            if (!$this->has('messages_conv_read_sender_idx')) $t->index(['conversation_id', 'read_at', 'sender_type'], 'messages_conv_read_sender_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('messages') && $this->has('messages_conv_read_sender_idx')) {
            Schema::table('messages', fn (Blueprint $t) => $t->dropIndex('messages_conv_read_sender_idx'));
        }
    }
};
