<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('returned_total', 18, 2)->default(0);
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->decimal('receivable_reduction', 18, 2)->default(0);
            $table->decimal('cash_refund_amount', 18, 2)->default(0);
            $table->string('refund_method', 32)->nullable();
            $table->decimal('restock_value', 18, 2)->default(0);
            $table->uuid('cash_shift_id')->nullable();
            $table->uuid('journal_entry_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropColumn(['receivable_reduction', 'cash_refund_amount', 'refund_method', 'restock_value', 'cash_shift_id', 'journal_entry_id']);
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('returned_total');
        });
    }
};
