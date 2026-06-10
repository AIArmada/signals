<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('signals.database.tables.alert_logs', 'signal_alert_logs'), function (Blueprint $table): void {
            $jsonColumnType = config('signals.database.json_column_type', commerce_json_column_type('signals', 'jsonb'));

            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
            $table->foreignUuid('signal_alert_rule_id');
            $table->foreignUuid('tracked_property_id')->nullable();
            $table->string('metric_key');
            $table->string('operator', 16);
            $table->decimal('metric_value', 20, 4)->default(0);
            $table->decimal('threshold_value', 20, 4)->default(0);
            $table->string('severity')->default('warning');
            $table->string('title');
            $table->text('message')->nullable();
            $table->{$jsonColumnType}('context')->nullable();
            $table->{$jsonColumnType}('channels_notified')->nullable();
            $table->{$jsonColumnType}('delivery_results')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['signal_alert_rule_id', 'read_at']);
            $table->index('tracked_property_id');
            $table->index(['severity', 'created_at']);
        });
    }
};
