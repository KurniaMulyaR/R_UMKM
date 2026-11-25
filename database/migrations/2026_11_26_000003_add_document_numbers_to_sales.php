<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('invoice_number', 64)->nullable();
            $table->string('delivery_note_number', 64)->nullable();
            $table->unique(['tenant_id', 'invoice_number'], 'sales_invoice_number_unique');
            $table->unique(['tenant_id', 'delivery_note_number'], 'sales_delivery_note_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_invoice_number_unique');
            $table->dropUnique('sales_delivery_note_number_unique');
            $table->dropColumn(['invoice_number', 'delivery_note_number']);
        });
    }
};
