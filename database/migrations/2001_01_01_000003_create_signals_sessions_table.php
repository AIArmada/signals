<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('signals.database.tables.sessions', 'signal_sessions'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tracked_property_id');
            $table->foreignUuid('signal_identity_id')->nullable();
            $table->string('session_identifier')->nullable();
            $table->nullableUuidMorphs('owner');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('duration_milliseconds')->default(0);
            $table->string('entry_path')->nullable();
            $table->string('exit_path')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('device_type')->nullable();
            $table->string('browser')->nullable();
            $table->string('os')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->boolean('is_bounce')->default(false);
            $table->timestamps();

            $table->index(['tracked_property_id', 'started_at']);
            $table->index(['signal_identity_id', 'started_at']);
            $table->index(['utm_source', 'utm_campaign']);
            $table->unique(['tracked_property_id', 'session_identifier']);
        });
    }
};
