<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('account_type', 24);
            $table->string('normal_balance', 8);
            $table->boolean('is_system')->default(false);
            $table->boolean('allow_manual_posting')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'account_type', 'is_active']);
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignUuid('reopened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('close_note')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'starts_on', 'ends_on']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entry_number', 64);
            $table->date('entry_date');
            $table->string('status', 16)->default('draft');
            $table->string('description');
            $table->string('source_type', 64)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('idempotency_key', 160)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignUuid('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('reversal_of_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'entry_number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'entry_date', 'status']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'account_id', 'journal_entry_id']);
        });

        Schema::create('accounting_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 64);
            $table->uuid('source_id');
            $table->string('event_type', 64);
            $table->string('status', 24)->default('pending');
            $table->foreignUuid('posted_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('expense_number', 64);
            $table->timestamp('spent_at');
            $table->string('payee')->nullable();
            $table->text('description')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 24)->default('draft');
            $table->foreignUuid('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'expense_number']);
            $table->index(['tenant_id', 'outlet_id', 'spent_at']);
        });

        Schema::create('expense_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('expense_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });

        Schema::create('sync_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_uuid');
            $table->string('aggregate_type', 64);
            $table->uuid('aggregate_uuid');
            $table->string('operation_type', 48);
            $table->unsignedSmallInteger('payload_version')->default(1);
            $table->string('payload_hash', 128)->nullable();
            $table->string('status', 24)->default('received');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedBigInteger('server_version')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'device_id', 'operation_uuid']);
            $table->index(['tenant_id', 'status', 'received_at']);
        });

        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->string('stream', 64);
            $table->unsignedBigInteger('last_server_sequence')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'device_id', 'stream']);
        });

        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('aggregate_type', 64);
            $table->uuid('aggregate_id');
            $table->string('event_type', 96);
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'occurred_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 96);
            $table->string('subject_type', 96);
            $table->uuid('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
            $table->index(['actor_user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        $tables = [
            'audit_logs', 'outbox_messages', 'sync_cursors', 'sync_operations', 'expense_items', 'expenses',
            'accounting_events', 'journal_lines', 'journal_entries', 'accounting_periods', 'chart_of_accounts',
        ];
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
