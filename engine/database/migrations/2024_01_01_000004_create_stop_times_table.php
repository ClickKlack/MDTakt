<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stop_times', function (Blueprint $table) {
            // GTFS-Zeiten können > 24:00:00 sein (Fahrten nach Mitternacht) — als String speichern
            $table->string('trip_id');
            $table->string('stop_id');
            $table->string('arrival_time', 8)->nullable();
            $table->string('departure_time', 8)->nullable();
            $table->unsignedSmallInteger('stop_sequence');

            $table->primary(['trip_id', 'stop_sequence']);

            $table->foreign('trip_id')->references('trip_id')->on('trips');
            $table->foreign('stop_id')->references('stop_id')->on('stops');

            $table->index('stop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stop_times');
    }
};
