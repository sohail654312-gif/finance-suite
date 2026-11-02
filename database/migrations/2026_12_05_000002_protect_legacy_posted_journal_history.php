<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER legacy_journal_'.strtolower($operation)." BEFORE {$operation} ON journal_entries
                    WHEN OLD.is_posted = 1 BEGIN SELECT RAISE(ABORT, 'Posted journal entries are immutable.'); END");
            }
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                $parents = match ($operation) {
                    'INSERT' => 'NEW.journal_entry_id',
                    'DELETE' => 'OLD.journal_entry_id',
                    default => 'OLD.journal_entry_id, NEW.journal_entry_id',
                };
                DB::unprepared('CREATE TRIGGER legacy_journal_line_'.strtolower($operation)." BEFORE {$operation} ON journal_entry_lines
                    WHEN EXISTS (SELECT 1 FROM journal_entries WHERE id IN ({$parents}) AND is_posted = 1)
                    BEGIN SELECT RAISE(ABORT, 'Posted journal entry lines are immutable.'); END");
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION protect_legacy_journal_history() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF OLD.is_posted THEN
                        RAISE EXCEPTION 'Posted journal entries are immutable.';
                    END IF;
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END;
                $$;
                CREATE TRIGGER legacy_journal_history BEFORE UPDATE OR DELETE ON journal_entries
                    FOR EACH ROW EXECUTE FUNCTION protect_legacy_journal_history();

                CREATE FUNCTION protect_legacy_journal_lines() RETURNS trigger LANGUAGE plpgsql AS $$
                DECLARE
                    parent_id bigint;
                    old_parent bigint;
                    new_parent bigint;
                    posted boolean;
                BEGIN
                    IF TG_OP <> 'INSERT' THEN old_parent := OLD.journal_entry_id; END IF;
                    IF TG_OP <> 'DELETE' THEN new_parent := NEW.journal_entry_id; END IF;
                    FOR parent_id IN SELECT DISTINCT id FROM journal_entries
                        WHERE id IN (old_parent, new_parent) ORDER BY id
                    LOOP
                        SELECT is_posted INTO posted FROM journal_entries WHERE id = parent_id FOR UPDATE;
                        IF posted THEN
                            RAISE EXCEPTION 'Posted journal entry lines are immutable.';
                        END IF;
                    END LOOP;
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END;
                $$;
                CREATE TRIGGER legacy_journal_line_history BEFORE INSERT OR UPDATE OR DELETE ON journal_entry_lines
                    FOR EACH ROW EXECUTE FUNCTION protect_legacy_journal_lines();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['legacy_journal_update', 'legacy_journal_delete', 'legacy_journal_line_insert', 'legacy_journal_line_update', 'legacy_journal_line_delete'] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS legacy_journal_line_history ON journal_entry_lines');
            DB::unprepared('DROP TRIGGER IF EXISTS legacy_journal_history ON journal_entries');
            DB::unprepared('DROP FUNCTION IF EXISTS protect_legacy_journal_lines()');
            DB::unprepared('DROP FUNCTION IF EXISTS protect_legacy_journal_history()');
        }
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropForeign(['reversal_of_id']);
            $table->dropUnique(['reversal_of_id']);
            $table->dropColumn('reversal_of_id');
        });
    }
};
