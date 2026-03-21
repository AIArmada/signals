<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('signals.database.tables.daily_metrics', 'signal_daily_metrics'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tracked_property_id');
            $table->nullableUuidMorphs('owner');
            $table->date('date');
            $table->unsignedInteger('unique_identities')->default(0);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('bounced_sessions')->default(0);
            $table->unsignedInteger('page_views')->default(0);
            $table->unsignedInteger('events')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->unsignedBigInteger('revenue_minor')->default(0);
            $table->timestamps();

            $table->unique(['tracked_property_id', 'date']);
            $table->index(['date', 'owner_type', 'owner_id']);
        });
    }
};
