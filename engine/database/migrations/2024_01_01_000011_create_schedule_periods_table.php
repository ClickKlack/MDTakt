<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Netzweite, kuratierte Fahrplanperiode (FAHRPLANPERIODEN §4.1). Orientiert sich an
        // den veröffentlichten MVB-Fahrplänen; eine neue Periode setzt alle Linien zurück.
        Schema::create('schedule_periods', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->date('valid_from');
            $table->date('valid_to')->nullable(); // offen = laufende Periode
            $table->string('status', 16);         // App\Enums\PeriodStatus
            $table->string('created_via', 16);    // App\Enums\PeriodOrigin
            $table->timestampsTz();

            $table->index('status');
            $table->index('valid_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_periods');
    }
};
