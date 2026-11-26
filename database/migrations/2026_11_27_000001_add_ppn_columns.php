<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('tax_mode', 12)->nullable()->after('tax_total');
            $table->decimal('tax_rate', 5, 2)->nullable()->after('tax_mode');
            $table->decimal('returned_tax_total', 18, 2)->default(0)->after('tax_rate');
        });
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->decimal('tax_total', 18, 2)->default(0);
        });
        Schema::table('sale_return_items', function (Blueprint $table) {
            $table->decimal('tax_amount', 18, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sale_return_items', fn (Blueprint $table) => $table->dropColumn('tax_amount'));
        Schema::table('sale_returns', fn (Blueprint $table) => $table->dropColumn('tax_total'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn(['tax_mode', 'tax_rate', 'returned_tax_total']));
    }
};
