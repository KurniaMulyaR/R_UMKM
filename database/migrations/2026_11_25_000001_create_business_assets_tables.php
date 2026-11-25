<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('category', 64)->default('other');
            $table->string('serial_number', 128)->nullable();
            $table->string('location')->nullable();
            $table->date('purchase_date');
            $table->decimal('purchase_cost', 18, 2);
            $table->unsignedSmallInteger('useful_life_months');
            $table->decimal('salvage_value', 18, 2)->default(0);
            $table->unsignedSmallInteger('maintenance_interval_days')->nullable();
            $table->date('next_maintenance_due')->nullable();
            $table->string('status', 24)->default('active');
            $table->date('disposed_at')->nullable();
            $table->string('disposal_reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'next_maintenance_due']);
        });

        Schema::create('asset_maintenance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('business_asset_id')->constrained()->cascadeOnDelete();
            $table->date('performed_at');
            $table->string('description', 500);
            $table->decimal('cost', 18, 2)->default(0);
            $table->string('performed_by')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'business_asset_id', 'performed_at'], 'asset_maintenance_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_records');
        Schema::dropIfExists('business_assets');
    }
};
