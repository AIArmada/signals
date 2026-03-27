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

        if (! Schema::hasTable($tableName)) {
            return;
        }

        $jsonColumnType = config('signals.database.json_column_type', 'json');

        Schema::table($tableName, function (Blueprint $table) use ($jsonColumnType): void {
            if (! Schema::hasColumn($table->getTable(), 'country_source')) {
                $table->string('country_source', 50)->nullable()->after('country');
            }

            if (! Schema::hasColumn($table->getTable(), 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('country_source');
            }

            if (! Schema::hasColumn($table->getTable(), 'longitude')) {
                $table->decimal('longitude', 11, 7)->nullable()->after('latitude');
            }

            if (! Schema::hasColumn($table->getTable(), 'accuracy_meters')) {
                $table->unsignedInteger('accuracy_meters')->nullable()->after('longitude');
            }

            if (! Schema::hasColumn($table->getTable(), 'geolocation_source')) {
                $table->string('geolocation_source', 50)->nullable()->after('accuracy_meters');
            }

            if (! Schema::hasColumn($table->getTable(), 'geolocation_captured_at')) {
                $table->timestamp('geolocation_captured_at')->nullable()->after('geolocation_source');
            }

            if (! Schema::hasColumn($table->getTable(), 'resolved_country_code')) {
                $table->string('resolved_country_code', 10)->nullable()->after('geolocation_captured_at');
            }

            if (! Schema::hasColumn($table->getTable(), 'resolved_country_name')) {
                $table->string('resolved_country_name', 100)->nullable()->after('resolved_country_code');
            }

            if (! Schema::hasColumn($table->getTable(), 'resolved_state')) {
                $table->string('resolved_state', 100)->nullable()->after('resolved_country_name');
            }

            if (! Schema::hasColumn($table->getTable(), 'resolved_city')) {
                $table->string('resolved_city', 100)->nullable()->after('resolved_state');
            }

            if (! Schema::hasColumn($table->getTable(), 'resolved_postcode')) {
                $table->string('resolved_postcode', 20)->nullable()->after('resolved_city');
            }

            if (! Schema::hasColumn($table->getTable(), 'resolved_formatted_address')) {
                $table->text('resolved_formatted_address')->nullable()->after('resolved_postcode');
            }

            if (! Schema::hasColumn($table->getTable(), 'reverse_geocode_provider')) {
                $table->string('reverse_geocode_provider', 50)->nullable()->after('resolved_formatted_address');
            }

            if (! Schema::hasColumn($table->getTable(), 'reverse_geocoded_at')) {
                $table->timestamp('reverse_geocoded_at')->nullable()->after('reverse_geocode_provider');
            }

            if (! Schema::hasColumn($table->getTable(), 'raw_reverse_geocode_payload')) {
                $table->{$jsonColumnType}('raw_reverse_geocode_payload')->nullable()->after('reverse_geocoded_at');
            }
        });
    }
};
