<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('account_category', function (Blueprint $table): void {
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->primary(['account_id', 'category_id']);
        });

        Schema::create('category_transaction', function (Blueprint $table): void {
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions', 'transaction_id')->cascadeOnDelete();
            $table->primary(['category_id', 'transaction_id']);
        });

        Schema::create('supplier_tax_rate', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->constrained('suppliers', 'supplier_id')->cascadeOnDelete();
            $table->foreignId('tax_rate_id')->constrained('tax_rates', 'tax_rate_id')->cascadeOnDelete();
            $table->primary(['supplier_id', 'tax_rate_id']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders', 'purchase_order_id')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
        Schema::dropIfExists('supplier_tax_rate');
        Schema::dropIfExists('category_transaction');
        Schema::dropIfExists('account_category');
    }
};
