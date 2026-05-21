<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('signals.database.tables.events', 'signal_events'), function (Blueprint $table): void {
            $jsonColumnType = config('signals.database.json_column_type', commerce_json_column_type('signals', 'json'));

            $table->uuid('id')->primary();
            $table->foreignUuid('tracked_property_id');
            $table->foreignUuid('signal_session_id')->nullable();
            $table->foreignUuid('signal_identity_id')->nullable();
            $table->nullableUuidMorphs('owner');
            $table->timestamp('occurred_at');
            $table->string('event_name');
            $table->string('event_category')->default('custom');
            $table->string('idempotency_key')->nullable();
            $table->string('source_event_id')->nullable();
            $table->string('path')->nullable();
            $table->text('url')->nullable();
            $table->text('referrer')->nullable();
            $table->string('source')->nullable();
            $table->string('medium')->nullable();
            $table->string('campaign')->nullable();
            $table->string('content')->nullable();
            $table->string('term')->nullable();
            $table->unsignedBigInteger('revenue_minor')->default(0);
            $table->string('currency', 3)->default(config('signals.defaults.currency', 'MYR'));
            $table->{$jsonColumnType}('properties')->nullable();
            $table->timestamps();

            $table->index(['tracked_property_id', 'occurred_at']);
            $table->index(['event_category', 'occurred_at']);
            $table->index(['event_name', 'occurred_at']);
            $table->unique(['tracked_property_id', 'idempotency_key']);
            $table->index(['tracked_property_id', 'source_event_id']);
            $table->index(['source', 'campaign']);
        });
    }
};
