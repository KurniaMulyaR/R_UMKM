<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignUuid('to_outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->string('transfer_number', 64);
            $table->string('status', 24)->default('in_transit');
            $table->text('notes')->nullable();
            $table->decimal('total_value', 18, 2)->default(0);
            $table->decimal('shortage_value', 18, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'transfer_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('received_quantity', 18, 4)->nullable();
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
