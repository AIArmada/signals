<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('signals.database.tables.alert_rules', 'signal_alert_rules'), function (Blueprint $table): void {
            $jsonColumnType = config('signals.database.json_column_type', commerce_json_column_type('signals', 'json'));

            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
            $table->string('owner_scope')->default('global');
            $table->foreignUuid('tracked_property_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('metric_key');
            $table->string('operator', 16);
            $table->decimal('threshold', 20, 4)->default(0);
            $table->{$jsonColumnType}('event_filters')->nullable();
            $table->{$jsonColumnType}('channels')->nullable();
            $table->{$jsonColumnType}('destination_keys')->nullable();
            $table->{$jsonColumnType}('inline_destinations')->nullable();
            $table->unsignedInteger('timeframe_minutes')->default(60);
            $table->unsignedInteger('cooldown_minutes')->default(60);
            $table->string('severity')->default('warning');
            $table->unsignedInteger('priority')->default(0);
            $table->timestamp('last_triggered_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['owner_scope', 'slug']);
            $table->index(['metric_key', 'is_active']);
            $table->index('tracked_property_id');
        });
    }
};
