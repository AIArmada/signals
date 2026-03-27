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

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'duration_milliseconds')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->unsignedBigInteger('duration_milliseconds')->default(0)->after('ended_at');
        });
    }
};
