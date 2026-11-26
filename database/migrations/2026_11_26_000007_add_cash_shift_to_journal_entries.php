<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jurnal yang menggerakkan uang di laci kas (aset tunai, modal/prive tunai) ditandai dengan shift-nya.
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignUuid('cash_shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_shift_id');
        });
    }
};
