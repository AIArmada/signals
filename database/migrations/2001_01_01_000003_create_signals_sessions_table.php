<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $jsonColumnType = config('signals.database.json_column_type', commerce_json_column_type('signals', 'jsonb'));

        Schema::create(config('signals.database.tables.sessions', 'signal_sessions'), function (Blueprint $table) use ($jsonColumnType): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tracked_property_id');
            $table->foreignUuid('signal_identity_id')->nullable();
            $table->string('session_identifier')->nullable();
            $table->nullableUuidMorphs('owner');
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedBigInteger('duration_milliseconds')->default(0);
            $table->string('entry_path')->nullable();
            $table->string('exit_path')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('country_source', 50)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->unsignedInteger('accuracy_meters')->nullable();
            $table->string('geolocation_source', 50)->nullable();
            $table->timestampTz('geolocation_captured_at')->nullable();
            $table->string('resolved_country_code', 10)->nullable();
            $table->string('resolved_country_name', 100)->nullable();
            $table->string('resolved_state', 100)->nullable();
            $table->string('resolved_city', 100)->nullable();
            $table->string('resolved_postcode', 20)->nullable();
            $table->text('resolved_formatted_address')->nullable();
            $table->string('reverse_geocode_provider', 50)->nullable();
            $table->timestampTz('reverse_geocoded_at')->nullable();
            $table->{$jsonColumnType}('raw_reverse_geocode_payload')->nullable();
            $table->string('device_type')->nullable();
            $table->string('device_brand', 100)->nullable();
            $table->string('device_model', 100)->nullable();
            $table->string('browser')->nullable();
            $table->string('browser_version', 50)->nullable();
            $table->string('os')->nullable();
            $table->string('os_version', 50)->nullable();
            $table->text('referrer')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->timestampTz('bounced_at')->nullable();
            $table->boolean('is_bounce')->default(false);
            $table->timestampTz('identified_as_bot_at')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampsTz();

            $table->index(['tracked_property_id', 'started_at']);
            $table->index(['signal_identity_id', 'started_at']);
            $table->index(['utm_source', 'utm_campaign']);
            $table->unique(['tracked_property_id', 'session_identifier']);
        });
    }
};
