<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('signals.database.tables.identities', 'signal_identities'), function (Blueprint $table): void {
            $jsonColumnType = commerce_json_column_type('signals', 'jsonb');

            $table->uuid('id')->primary();
            $table->foreignUuid('tracked_property_id');
            $table->nullableUuidMorphs('owner');
            $table->string('external_id')->nullable();
            $table->string('anonymous_id')->nullable();
            $table->string('email')->nullable();
            $table->{$jsonColumnType}('traits')->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->string('auth_user_type')->nullable();
            $table->string('auth_user_id')->nullable();
            $table->timestampsTz();

            $table->index(['tracked_property_id', 'last_seen_at']);
            $table->index('anonymous_id');
            $table->index(['auth_user_type', 'auth_user_id']);
            $table->unique(['tracked_property_id', 'external_id']);
        });
    }
};
