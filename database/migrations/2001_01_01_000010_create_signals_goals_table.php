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
            $jsonColumnType = config('signals.database.json_column_type', commerce_json_column_type('signals', 'json'));

            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
            $table->foreignUuid('tracked_property_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('goal_type')->default('conversion');
            $table->string('event_name');
            $table->string('event_category')->nullable();
            $table->{$jsonColumnType}('conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'slug']);
            $table->index(['tracked_property_id', 'is_active']);
            $table->index(['event_name', 'event_category']);
        });
    }
};
