<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_assets', function (Blueprint $table) {
            $table->foreignUuid('acquisition_journal_entry_id')->nullable()->after('notes')->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acquisition_journal_entry_id');
        });
    }
};
