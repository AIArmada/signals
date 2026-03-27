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
            if (! Schema::hasColumn($table->getTable(), 'browser_version')) {
                $table->string('browser_version', 50)->nullable()->after('browser');
            }

            if (! Schema::hasColumn($table->getTable(), 'os_version')) {
                $table->string('os_version', 50)->nullable()->after('os');
            }

            if (! Schema::hasColumn($table->getTable(), 'device_brand')) {
                $table->string('device_brand', 100)->nullable()->after('device_type');
            }

            if (! Schema::hasColumn($table->getTable(), 'device_model')) {
                $table->string('device_model', 100)->nullable()->after('device_brand');
            }

            if (! Schema::hasColumn($table->getTable(), 'is_bot')) {
                $table->boolean('is_bot')->default(false)->after('is_bounce');
            }

            if (! Schema::hasColumn($table->getTable(), 'user_agent')) {
                $table->text('user_agent')->nullable()->after('is_bot');
            }

            if (! Schema::hasColumn($table->getTable(), 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('user_agent');
            }
        });
    }
};
