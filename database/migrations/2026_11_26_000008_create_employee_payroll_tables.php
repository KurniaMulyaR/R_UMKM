<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('position', 120)->nullable();
            $table->string('phone', 32)->nullable();
            $table->date('joined_on')->nullable();
            $table->decimal('base_salary', 18, 2)->default(0);
            $table->decimal('fixed_allowance', 18, 2)->default(0);
            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('employee_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->date('reviewed_on');
            $table->unsignedTinyInteger('rating');
            $table->text('notes')->nullable();
            $table->string('reward_note', 255)->nullable();
            $table->decimal('previous_salary', 18, 2)->default(0);
            $table->decimal('new_salary', 18, 2)->nullable();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 7);
            $table->string('status', 16)->default('draft');
            $table->decimal('total_net', 18, 2)->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 24)->nullable();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUuid('payment_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'period_key']);
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->restrictOnDelete();
            $table->string('employee_name', 160);
            $table->string('position', 120)->nullable();
            $table->decimal('base_salary', 18, 2)->default(0);
            $table->decimal('allowance', 18, 2)->default(0);
            $table->decimal('bonus', 18, 2)->default(0);
            $table->decimal('deduction', 18, 2)->default(0);
            $table->decimal('net_pay', 18, 2)->default(0);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employee_reviews');
        Schema::dropIfExists('employees');
    }
};
