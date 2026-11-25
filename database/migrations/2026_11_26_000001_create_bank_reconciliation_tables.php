<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 18, 2);
            $table->string('status', 16)->default('draft');
            $table->text('note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUuid('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'account_id', 'status']);
        });

        Schema::create('reconciled_journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bank_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('journal_line_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique('journal_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciled_journal_lines');
        Schema::dropIfExists('bank_reconciliations');
    }
};
