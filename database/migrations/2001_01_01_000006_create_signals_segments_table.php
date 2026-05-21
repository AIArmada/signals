<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('signals.database.tables.segments', 'signal_segments'), function (Blueprint $table): void {
            $jsonColumnType = config('signals.database.json_column_type', commerce_json_column_type('signals', 'json'));

            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('match_type')->default('all');
            $table->{$jsonColumnType}('conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'slug']);
            $table->index(['match_type', 'is_active']);
        });
    }
};
