<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('signals.database.tables.events', 'signal_events');

        if (Schema::hasColumn($table, 'property_types')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $jsonColumnType = config('signals.database.json_column_type', commerce_json_column_type('signals', 'json'));

            $table->{$jsonColumnType}('property_types')->nullable()->after('properties');
        });
    }
};
