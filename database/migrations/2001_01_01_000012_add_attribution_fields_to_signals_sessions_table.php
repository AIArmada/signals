<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sessionTable = config('signals.database.tables.sessions', 'signal_sessions');

        if (! Schema::hasTable($sessionTable)) {
            return;
        }

        Schema::table($sessionTable, function (Blueprint $table): void {
            if (! Schema::hasColumn($table->getTable(), 'referrer')) {
                $table->text('referrer')->nullable()->after('os');
            }

            if (! Schema::hasColumn($table->getTable(), 'utm_content')) {
                $table->string('utm_content')->nullable()->after('utm_campaign');
            }

            if (! Schema::hasColumn($table->getTable(), 'utm_term')) {
                $table->string('utm_term')->nullable()->after('utm_content');
            }
        });
    }
};
