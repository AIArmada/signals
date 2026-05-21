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
            $jsonColumnType = config('signals.database.json_column_type', commerce_json_column_type('signals', 'json'));

            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
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
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'slug']);
            $table->index(['report_type', 'is_active']);
            $table->index('tracked_property_id');
            $table->index('signal_segment_id');
        });
    }
};
