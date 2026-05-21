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
            $jsonColumnType = config('signals.database.json_column_type', commerce_json_column_type('signals', 'json'));

            $table->uuid('id')->primary();
            $table->foreignUuid('tracked_property_id');
            $table->nullableUuidMorphs('owner');
            $table->string('external_id')->nullable();
            $table->string('anonymous_id')->nullable();
            $table->string('email')->nullable();
            $table->{$jsonColumnType}('traits')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['tracked_property_id', 'last_seen_at']);
            $table->index('anonymous_id');
            $table->unique(['tracked_property_id', 'external_id']);
        });
    }
};
