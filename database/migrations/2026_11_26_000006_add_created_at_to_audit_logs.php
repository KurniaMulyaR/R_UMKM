<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Semua penulis audit log mengisi created_at, tetapi tabel awal hanya punya occurred_at.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('audit_logs', 'created_at')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('audit_logs', 'created_at')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropColumn('created_at');
            });
        }
    }
};
