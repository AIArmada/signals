<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $identityTable = config('signals.database.tables.identities', 'signal_identities');

        if (! Schema::hasTable($identityTable)) {
            return;
        }

        Schema::table($identityTable, function (Blueprint $table): void {
            if (! Schema::hasColumn($table->getTable(), 'auth_user_type')) {
                $table->string('auth_user_type')->nullable()->after('last_seen_at');
            }

            if (! Schema::hasColumn($table->getTable(), 'auth_user_id')) {
                $table->string('auth_user_id')->nullable()->after('auth_user_type');
            }

            if (! Schema::hasIndex($table->getTable(), 'signal_identities_auth_user_type_auth_user_id_index')) {
                $table->index(['auth_user_type', 'auth_user_id']);
            }
        });
    }
};
