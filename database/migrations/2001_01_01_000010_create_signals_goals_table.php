<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('signals.database.tables.goals', 'signal_goals'), function (Blueprint $table): void {
            $jsonColumnType = commerce_json_column_type('signals', 'jsonb');

            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
            $table->string('owner_scope', 64)->default('global');
            $table->foreignUuid('tracked_property_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('goal_type')->default('conversion');
            $table->string('event_name');
            $table->string('event_category')->nullable();
            $table->{$jsonColumnType}('conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['owner_scope', 'slug']);
            $table->index(['tracked_property_id', 'is_active']);
            $table->index(['event_name', 'event_category']);
        });
    }
};
