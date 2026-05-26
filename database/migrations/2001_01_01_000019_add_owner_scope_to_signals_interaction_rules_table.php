<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('signals.database.tables.interaction_rules', 'signal_interaction_rules');

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (! Schema::hasColumn($tableName, 'owner_scope')) {
                $table->string('owner_scope')->default('global')->after('owner_id');
                $table->index(['owner_scope', 'slug']);
            }
        });
    }
};
