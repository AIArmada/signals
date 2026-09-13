<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('signals.database.tables.alert_deliveries', 'signal_alert_deliveries'), function (Blueprint $table): void {
            $jsonColumnType = commerce_json_column_type('signals', 'jsonb');

            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
            $table->foreignUuid('signal_alert_log_id');
            $table->string('channel', 32);
            $table->string('destination_key', 128);
            $table->{$jsonColumnType}('destination');
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('leased_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('dead_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['signal_alert_log_id', 'channel', 'destination_key'], 'signal_alert_delivery_unique');
            $table->index(['status', 'available_at']);
            $table->index(['signal_alert_log_id', 'status']);
        });
    }
};
