<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('signals.database.tables.sessions', 'signal_sessions');

        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'duration_seconds')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn('duration_seconds');
        });
    }
};
