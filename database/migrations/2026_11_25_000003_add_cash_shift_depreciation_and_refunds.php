<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignUuid('cash_shift_id')->nullable()->after('outlet_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'cash_shift_id', 'status'], 'expenses_shift_lookup_idx');
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->decimal('refund_due', 18, 2)->default(0)->after('total');
            $table->decimal('refunded_total', 18, 2)->default(0)->after('refund_due');
        });

        Schema::create('purchase_return_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_account_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('cash_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 32);
            $table->decimal('amount', 18, 2);
            $table->timestamp('received_at');
            $table->foreignUuid('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'cash_shift_id', 'method'], 'purchase_refunds_shift_idx');
        });

        Schema::create('asset_depreciation_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('business_asset_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->decimal('amount', 18, 2);
            $table->foreignUuid('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['business_asset_id', 'period']);
            $table->index(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_entries');
        Schema::dropIfExists('purchase_return_refunds');
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn(['refund_due', 'refunded_total']);
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_shift_lookup_idx');
            $table->dropConstrainedForeignId('cash_shift_id');
        });
    }
};
