<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('business_type', 40)->nullable();
            $table->string('legal_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->char('currency', 3)->default('IDR');
            $table->string('status', 24)->default('active');
            $table->json('settings')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('outlets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->text('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->boolean('is_default')->default(false);
            $table->string('status', 24)->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('tenant_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key', 64);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 100)->unique();
            $table->string('name');
            $table->string('module', 48);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('tenant_user_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_user_id')->constrained('tenant_user')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tenant_user_id', 'role_id']);
        });

        Schema::create('user_outlet', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_user_id')->constrained('tenant_user')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['tenant_user_id', 'outlet_id']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('symbol', 16);
            $table->unsignedTinyInteger('precision')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'symbol']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'parent_id']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->foreignUuid('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->string('sku', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('product_type', 24)->default('stocked');
            $table->boolean('track_stock')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('is_weighed')->default(false);
            $table->boolean('is_serialized')->default(false);
            $table->decimal('min_stock', 18, 4)->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'category_id', 'is_active']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Default');
            $table->string('sku', 80)->nullable();
            $table->json('attributes')->nullable();
            $table->decimal('cost_price', 18, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'product_id']);
        });

        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('base_quantity', 18, 4);
            $table->boolean('is_purchase_unit')->default(false);
            $table->boolean('is_sell_unit')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'unit_id']);
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('barcode', 128);
            $table->string('type', 24)->default('internal');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'product_variant_id']);
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('price_type', 32)->default('retail');
            $table->decimal('price', 18, 2);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'product_variant_id', 'price_type', 'is_active'], 'product_prices_lookup_idx');
        });

        Schema::create('bundle_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bundle_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignUuid('component_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->timestamps();
            $table->unique(['bundle_variant_id', 'component_variant_id']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('credit_limit', 18, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'phone']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        $tables = [
            'suppliers', 'customers', 'bundle_components', 'product_prices', 'product_barcodes',
            'product_unit_conversions', 'product_variants', 'products', 'product_categories', 'units',
            'user_outlet', 'tenant_user_roles', 'role_permissions', 'permissions', 'roles',
            'tenant_user', 'outlets', 'tenants',
        ];
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
