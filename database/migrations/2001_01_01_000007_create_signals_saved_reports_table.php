<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('signals.database.tables.saved_reports', 'signal_saved_reports'), function (Blueprint $table): void {
            $jsonColumnType = commerce_json_column_type('signals', 'jsonb');

            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
            $table->string('owner_scope', 64)->default('global');
            $table->foreignUuid('tracked_property_id')->nullable();
            $table->foreignUuid('signal_segment_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('report_type');
            $table->text('description')->nullable();
            $table->{$jsonColumnType}('filters')->nullable();
            $table->{$jsonColumnType}('settings')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['owner_scope', 'slug']);
            $table->index(['report_type', 'is_active']);
            $table->index('tracked_property_id');
            $table->index('signal_segment_id');
        });
    }
};
