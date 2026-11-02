<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounting_journal_entries', 'team_id')) {
            Schema::table('accounting_journal_entries', function (Blueprint $table): void {
                $table->unsignedBigInteger('team_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounting_journal_entries', 'team_id')) {
            Schema::table('accounting_journal_entries', fn (Blueprint $table) => $table->dropColumn('team_id'));
        }
    }
};
