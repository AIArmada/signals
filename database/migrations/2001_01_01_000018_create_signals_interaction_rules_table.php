<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('signals.database.tables.interaction_rules', 'signal_interaction_rules'), function (Blueprint $table): void {
            $jsonColumnType = config('signals.database.json_column_type', commerce_json_column_type('signals', 'json'));

            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
            $table->string('owner_scope')->default('global');
            $table->foreignUuid('tracked_property_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('trigger_type')->default('click');
            $table->string('event_name');
            $table->string('event_category')->nullable();
            $table->string('selector')->nullable();
            $table->string('page_pattern')->nullable();
            $table->{$jsonColumnType}('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['owner_scope', 'slug']);
            $table->index(['tracked_property_id', 'is_active']);
            $table->index(['trigger_type', 'is_active']);
            $table->index(['event_name', 'event_category']);
        });
    }
};
