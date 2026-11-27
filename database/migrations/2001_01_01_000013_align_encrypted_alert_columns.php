<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $rulesTable = (string) config('signals.database.tables.alert_rules', 'signal_alert_rules');
        $deliveriesTable = (string) config('signals.database.tables.alert_deliveries', 'signal_alert_deliveries');

        $this->assertNoLegacyPlaintext($rulesTable, 'inline_destinations');
        $this->assertNoLegacyPlaintext($deliveriesTable, 'destination');

        $this->convertJsonColumnToText($rulesTable, 'inline_destinations');
        $this->convertJsonColumnToText($deliveriesTable, 'destination');

        $this->addSignalEventIndexes();
    }

    private function assertNoLegacyPlaintext(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $value = DB::table($table)->whereNotNull($column)->value($column);

        if ($value === null) {
            return;
        }

        if (is_array($value) || (is_string($value) && json_validate($value))) {
            throw new RuntimeException(sprintf(
                'Legacy plaintext values remain in %s.%s. The columns now store encrypted:array payloads, so plaintext JSON cannot be read by the new cast. Clear or re-encrypt the values before running this migration.',
                $table,
                $column,
            ));
        }
    }

    private function convertJsonColumnToText(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        if (! in_array(Schema::getColumnType($table, $column), ['json', 'jsonb'], true)) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedColumn = $grammar->wrap($column);

        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN %s TYPE text USING %s::text',
                $wrappedTable,
                $wrappedColumn,
                $wrappedColumn,
            )),
            'mysql', 'mariadb' => DB::statement(sprintf(
                'ALTER TABLE %s MODIFY %s LONGTEXT',
                $wrappedTable,
                $wrappedColumn,
            )),
            default => null,
        };
    }

    private function addSignalEventIndexes(): void
    {
        $eventsTable = (string) config('signals.database.tables.events', 'signal_events');

        if (! Schema::hasTable($eventsTable)) {
            return;
        }

        if (! Schema::hasIndex($eventsTable, ['signal_session_id', 'id'])) {
            Schema::table($eventsTable, function (Blueprint $table): void {
                $table->index(['signal_session_id', 'id']);
            });
        }

        if (! Schema::hasIndex($eventsTable, ['signal_identity_id'])) {
            Schema::table($eventsTable, function (Blueprint $table): void {
                $table->index(['signal_identity_id']);
            });
        }
    }
};
