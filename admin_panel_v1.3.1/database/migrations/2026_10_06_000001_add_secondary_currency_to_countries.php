<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom: optional second "display" currency per country (e.g. Lebanon: USD prices
 * shown with an approximate LBP amount). Display only — orders, payments and
 * reports stay in the country's main currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            if (!Schema::hasColumn('countries', 'secondary_currency')) {
                $table->string('secondary_currency')->nullable()->after('decimal_point');
                $table->string('secondary_currency_code')->nullable()->after('secondary_currency');
                $table->decimal('secondary_exchange_rate', 18, 6)->nullable()->after('secondary_currency_code');
                $table->unsignedTinyInteger('secondary_decimal_point')->default(0)->after('secondary_exchange_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn(['secondary_currency', 'secondary_currency_code', 'secondary_exchange_rate', 'secondary_decimal_point']);
        });
    }
};
