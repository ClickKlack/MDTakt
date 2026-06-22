<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GTFS calendar.txt — reguläres Wochenmuster der Betriebstage je service_id.
        // Ausnahmen dazu stehen in calendar_dates.
        Schema::create('calendar', function (Blueprint $table) {
            $table->string('service_id')->primary();
            $table->boolean('monday');
            $table->boolean('tuesday');
            $table->boolean('wednesday');
            $table->boolean('thursday');
            $table->boolean('friday');
            $table->boolean('saturday');
            $table->boolean('sunday');
            $table->date('start_date');
            $table->date('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar');
    }
};
