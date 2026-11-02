<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounting_legal_entities', 'team_id')) {
            Schema::table('accounting_legal_entities', function (Blueprint $table): void {
                $table->unsignedBigInteger('team_id')->nullable()->index();
            });
        }

        if (! Schema::hasColumn('accounting_books', 'team_id')) {
            Schema::table('accounting_books', function (Blueprint $table): void {
                $table->unsignedBigInteger('team_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounting_books', 'team_id')) {
            Schema::table('accounting_books', fn (Blueprint $table) => $table->dropColumn('team_id'));
        }

        if (Schema::hasColumn('accounting_legal_entities', 'team_id')) {
            Schema::table('accounting_legal_entities', fn (Blueprint $table) => $table->dropColumn('team_id'));
        }
    }
};
