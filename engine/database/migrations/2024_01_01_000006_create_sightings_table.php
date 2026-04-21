<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sightings', function (Blueprint $table) {
            $table->id();
            $table->string('course_number');
            $table->string('line');
            $table->string('direction')->nullable();
            $table->timestampTz('observed_at');
            $table->string('stop_name')->nullable();
            $table->string('assigned_trip_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('assigned_trip_id')->references('trip_id')->on('trips')->nullOnDelete();

            $table->index('course_number');
            $table->index('observed_at');
            $table->index('assigned_trip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sightings');
    }
};
