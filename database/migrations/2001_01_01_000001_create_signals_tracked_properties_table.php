<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('signals.database.tables.tracked_properties', 'signal_tracked_properties'), function (Blueprint $table): void {
            $jsonColumnType = config('signals.database.json_column_type', commerce_json_column_type('signals', 'json'));

            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
            $table->string('owner_scope')->default('global');
            $table->string('name');
            $table->string('slug');
            $table->string('write_key')->unique();
            $table->string('domain')->nullable();
            $table->string('type')->default(config('signals.defaults.property_type', 'website'));
            $table->string('timezone')->default(config('signals.defaults.timezone', 'UTC'));
            $table->string('currency', 3)->default(config('signals.defaults.currency', 'MYR'));
            $table->boolean('is_active')->default(true);
            $table->{$jsonColumnType}('settings')->nullable();
            $table->timestamps();

            $table->unique(['owner_scope', 'slug']);
            $table->index(['type', 'is_active']);
        });
    }
};
