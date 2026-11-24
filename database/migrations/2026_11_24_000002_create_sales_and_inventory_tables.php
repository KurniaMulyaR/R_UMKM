<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_uuid');
            $table->string('name')->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('token_hash', 128)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'device_uuid']);
        });

        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cashier_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('open_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_cash', 18, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 18, 2)->nullable();
            $table->decimal('counted_cash', 18, 2)->nullable();
            $table->decimal('cash_difference', 18, 2)->nullable();
            $table->string('status', 24)->default('open');
            $table->text('close_note')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'outlet_id', 'status']);
            $table->index(['tenant_id', 'cashier_user_id', 'opened_at']);
        });

        Schema::create('document_counters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('document_type', 32);
            $table->string('period_key', 16);
            $table->string('prefix', 32)->nullable();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(4);
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'document_type', 'period_key'], 'document_counters_scope_unique');
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('cashier_user_id')->constrained('users')->restrictOnDelete();
            $table->string('sale_number', 64);
            $table->uuid('client_transaction_id');
            $table->string('status', 32)->default('draft');
            $table->string('channel', 32)->default('pos');
            $table->timestamp('sold_at')->nullable();
            $table->char('currency', 3)->default('IDR');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('fee_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->decimal('paid_total', 18, 2)->default(0);
            $table->decimal('change_total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('source', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignUuid('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'sale_number']);
            $table->unique(['tenant_id', 'client_transaction_id']);
            $table->index(['tenant_id', 'outlet_id', 'sold_at']);
            $table->index(['tenant_id', 'status', 'sold_at']);
            $table->index(['tenant_id', 'customer_id', 'sold_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('sku_snapshot', 80)->nullable();
            $table->string('name_snapshot');
            $table->string('variant_snapshot')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->string('discount_type', 16)->nullable();
            $table->decimal('discount_value', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('unit_cost_snapshot', 18, 2)->default(0);
            $table->decimal('line_subtotal', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->text('note')->nullable();
            $table->string('line_type', 24)->default('product');
            $table->timestamps();
            $table->index(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'product_variant_id']);
        });

        Schema::create('sale_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('label')->nullable();
            $table->string('calculation_method', 16)->default('fixed');
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });

        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->string('provider', 64)->nullable();
            $table->string('account_reference', 128)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'type', 'is_active']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('cash_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_number', 64);
            $table->string('method', 32);
            $table->string('status', 24)->default('pending');
            $table->decimal('amount', 18, 2);
            $table->timestamp('received_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->string('idempotency_key', 128);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'payment_number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'provider', 'provider_reference']);
            $table->index(['tenant_id', 'status', 'received_at']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->index(['tenant_id', 'sale_id']);
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('provider_event_id', 160);
            $table->string('event_type', 64);
            $table->timestamp('received_at');
            $table->string('payload_hash', 128)->nullable();
            $table->string('verification_status', 24)->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_event_id']);
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained()->restrictOnDelete();
            $table->string('return_number', 64);
            $table->string('status', 24)->default('draft');
            $table->string('reason', 255)->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('refund_total', 18, 2)->default(0);
            $table->string('restock_policy', 24)->default('good_condition');
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'return_number']);
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->string('condition', 24)->nullable();
            $table->decimal('restock_quantity', 18, 4)->default(0);
            $table->decimal('refund_amount', 18, 2);
            $table->string('reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->string('movement_type', 32);
            $table->decimal('quantity_delta', 18, 4);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('value_delta', 18, 2)->default(0);
            $table->string('source_type', 64);
            $table->uuid('source_id');
            $table->uuid('source_line_id')->nullable();
            $table->string('idempotency_key', 160);
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'outlet_id', 'product_variant_id', 'occurred_at'], 'stock_history_idx');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'stock_source_idx');
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity_on_hand', 18, 4)->default(0);
            $table->decimal('inventory_value', 18, 2)->default(0);
            $table->decimal('average_cost', 18, 2)->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'product_variant_id']);
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('count_number', 64);
            $table->string('status', 24)->default('draft');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'count_number']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('system_quantity_snapshot', 18, 4);
            $table->decimal('counted_quantity', 18, 4)->nullable();
            $table->decimal('variance', 18, 4)->nullable();
            $table->decimal('unit_cost_snapshot', 18, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['stock_count_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        $tables = [
            'stock_count_items', 'stock_counts', 'stock_balances', 'stock_movements', 'sale_return_items',
            'sale_returns', 'payment_events', 'payment_allocations', 'payments', 'cash_accounts',
            'sale_adjustments', 'sale_items', 'sales', 'document_counters', 'cash_shifts', 'devices',
        ];
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
