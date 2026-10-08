<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounting_chart_accounts', 'team_id')) {
            Schema::table('accounting_chart_accounts', function (Blueprint $table): void {
                $table->unsignedBigInteger('team_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounting_chart_accounts', 'team_id')) {
            Schema::table('accounting_chart_accounts', fn (Blueprint $table) => $table->dropColumn('team_id'));
        }
    }
};
