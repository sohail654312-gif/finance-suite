<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /** @var array<string, list<string>> */
    private array $columns = [
        'accounts' => ['sage_id'],
        'bills' => ['sage_id'],
        'credit_memos' => ['qbo_id', 'xero_id', 'sage_id'],
        'customers' => ['qbo_id', 'xero_id', 'sage_id'],
        'estimates' => ['qbo_id', 'xero_id', 'sage_id'],
        'payments' => ['sage_id'],
        'vendors' => ['qbo_id', 'xero_id', 'sage_id'],
    ];

    public function up(): void
    {
        foreach ($this->columns as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->string($column)->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->columns as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->dropIndex([$column]);
                    $table->dropColumn($column);
                }
            });
        }
    }
};
