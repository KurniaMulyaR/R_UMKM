<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignUuid('cash_shift_id')->nullable()->after('outlet_id')->constrained()->nullOnDelete();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['tenant_id', 'cash_shift_id', 'method', 'status'], 'payments_shift_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_shift_lookup_idx');
            $table->dropConstrainedForeignId('cash_shift_id');
        });
    }
};
