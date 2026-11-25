<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 24)->default('xendit');
            $table->text('secret_key')->nullable();
            $table->text('callback_token')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'provider']);
        });

        Schema::create('payment_gateway_charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cashier_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 24)->default('xendit');
            $table->string('reference_id', 64)->unique();
            $table->string('provider_qr_id', 96)->nullable();
            $table->string('provider_payment_id', 96)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('status', 24)->default('pending');
            $table->text('qr_string')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('payload');
            $table->uuid('sale_id')->nullable();
            $table->json('raw_callback')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_charges');
        Schema::dropIfExists('payment_gateway_settings');
    }
};
