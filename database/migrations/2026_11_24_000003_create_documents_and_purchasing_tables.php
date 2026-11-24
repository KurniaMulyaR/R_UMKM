<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number', 64);
            $table->string('status', 24)->default('draft');
            $table->timestamp('ordered_at')->nullable();
            $table->json('delivery_address_snapshot')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('shipping_fee', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'order_number']);
            $table->index(['tenant_id', 'status', 'ordered_at']);
        });

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku_snapshot', 80)->nullable();
            $table->string('name_snapshot');
            $table->string('unit_snapshot', 32)->nullable();
            $table->decimal('ordered_quantity', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
        });

        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sales_order_id')->constrained()->restrictOnDelete();
            $table->string('delivery_number', 64);
            $table->string('status', 24)->default('draft');
            $table->timestamp('shipped_at')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number', 128)->nullable();
            $table->json('delivery_address_snapshot')->nullable();
            $table->timestamp('proof_received_at')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'delivery_number']);
        });

        Schema::create('delivery_note_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('delivery_note_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sales_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku_snapshot', 80)->nullable();
            $table->string('name_snapshot');
            $table->decimal('quantity', 18, 4);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number', 64);
            $table->string('status', 24)->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->json('business_snapshot')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('shipping_fee', 18, 2)->default(0);
            $table->decimal('manual_tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_total', 18, 2)->default(0);
            $table->decimal('balance_due', 18, 2)->default(0);
            $table->timestamp('canceled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'invoice_number']);
            $table->index(['tenant_id', 'customer_id', 'status', 'due_at']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sales_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku_snapshot', 80)->nullable();
            $table->string('name_snapshot');
            $table->string('unit_snapshot', 32)->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('cost_snapshot', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
        });

        Schema::create('invoice_delivery_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('delivery_note_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['invoice_id', 'delivery_note_id']);
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number', 64);
            $table->timestamp('issued_at');
            $table->decimal('amount', 18, 2);
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'receipt_number']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->string('po_number', 64);
            $table->string('status', 24)->default('draft');
            $table->timestamp('ordered_at')->nullable();
            $table->date('needed_at')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'po_number']);
            $table->index(['tenant_id', 'supplier_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku_snapshot', 80)->nullable();
            $table->string('name_snapshot');
            $table->decimal('ordered_quantity', 18, 4);
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('received_quantity', 18, 4)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number', 64);
            $table->string('status', 24)->default('draft');
            $table->timestamp('received_at')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'receipt_number']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_snapshot');
            $table->decimal('quantity', 18, 4);
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('unit_cost', 18, 2);
            $table->string('condition', 24)->default('good');
            $table->string('lot_reference', 128)->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bill_number', 64);
            $table->string('supplier_invoice_number', 128)->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestamp('billed_at')->nullable();
            $table->date('due_at')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_total', 18, 2)->default(0);
            $table->decimal('balance_due', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'outlet_id', 'bill_number']);
            $table->index(['tenant_id', 'supplier_id', 'status', 'due_at']);
        });

        Schema::create('supplier_bill_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('goods_receipt_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_snapshot');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignUuid('invoice_id')->nullable()->after('sale_id')->constrained()->nullOnDelete();
            $table->foreignUuid('supplier_bill_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_bill_id');
            $table->dropConstrainedForeignId('invoice_id');
        });

        $tables = [
            'supplier_bill_items', 'supplier_bills', 'goods_receipt_items', 'goods_receipts',
            'purchase_order_items', 'purchase_orders', 'receipts', 'invoice_delivery_notes',
            'invoice_items', 'invoices', 'delivery_note_items', 'delivery_notes',
            'sales_order_items', 'sales_orders',
        ];
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
