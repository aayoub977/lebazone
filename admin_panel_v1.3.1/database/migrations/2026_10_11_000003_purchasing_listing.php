<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Lebazone: Purchasing → store listing. Description, extra photos (incl. AI-made ones) and a barcode
// per item, background AI jobs, and the "Lebazone" brand that every purchased product goes under.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('pur_items')) {
            Schema::table('pur_items', function (Blueprint $t) {
                if (!Schema::hasColumn('pur_items', 'description')) $t->text('description')->nullable();
                if (!Schema::hasColumn('pur_items', 'photos')) $t->text('photos')->nullable();      // json list of storage paths, in order
                if (!Schema::hasColumn('pur_items', 'barcode')) $t->string('barcode', 64)->nullable();
            });
        }
        if (!Schema::hasTable('pur_ai_jobs')) {
            Schema::create('pur_ai_jobs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('item_id')->index();
                $t->string('kind', 20);                 // photo | description
                $t->string('style', 40)->nullable();    // studio, angle, lifestyle, detail, …
                $t->string('status', 12)->default('queued'); // queued | running | done | failed
                $t->text('result')->nullable();         // storage path (photo) or text (description)
                $t->text('error')->nullable();
                $t->timestamps();
            });
        }

        // The "Lebazone" brand
        if (Schema::hasTable('brands')) {
            $id = DB::table('brands')->whereRaw('LOWER(name) = ?', ['lebazone'])->value('id');
            if (!$id) {
                $logo = (string) (DB::table('settings')->where('variable', 'logo')->value('value') ?? '');
                $id = DB::table('brands')->insertGetId(['name' => 'Lebazone', 'image' => $logo, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            if (Schema::hasTable('brand_translations') && Schema::hasTable('languages')) {
                foreach (DB::table('languages')->pluck('id') as $lang) {
                    DB::table('brand_translations')->updateOrInsert(['brand_id' => $id, 'language_id' => $lang],
                        ['name' => 'Lebazone', 'updated_at' => now(), 'created_at' => now()]);
                }
            }
            if (Schema::hasTable('pur_settings')) {
                DB::table('pur_settings')->updateOrInsert(['key' => 'brand_id'], ['value' => (string) $id, 'updated_at' => now()]);
                // Everything that came in through Purchasing goes under the Lebazone brand.
                $ids = DB::table('pur_items')->whereNotNull('product_id')->pluck('product_id')->unique()->all();
                if ($ids) DB::table('products')->whereIn('id', $ids)->update(['brand_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pur_ai_jobs');
        if (Schema::hasTable('pur_items')) {
            Schema::table('pur_items', function (Blueprint $t) {
                foreach (['description', 'photos', 'barcode'] as $c) if (Schema::hasColumn('pur_items', $c)) $t->dropColumn($c);
            });
        }
    }
};
