<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `status` war ein gespeicherter Wert, der von der Uhr abhing — und deshalb veraltete.
     *
     * Gerechnet wurde er nur beim Schreiben einer Periode (`SchedulePeriodService::rebuildChain`).
     * Wer am 20.09. eine Periode ab dem 21.09. anlegte, hatte am 21.09. eine Kette, in der die
     * abgelaufene Periode weiterhin `current` trug und die geltende `frozen`. Alle Ansichten, die
     * „die laufende Periode" über diese Spalte suchten, zeigten damit den falschen Fahrplan.
     *
     * `valid_to` bleibt gespeichert: Es hängt allein an der Nachbarperiode, ändert sich also nur,
     * wenn ohnehin geschrieben wird. `status` hängt zusätzlich am heutigen Tag und wird deshalb
     * beim Lesen aus `valid_from`/`valid_to` abgeleitet (`SchedulePeriod::status`).
     */
    public function up(): void
    {
        Schema::table('schedule_periods', function (Blueprint $table) {
            $table->dropIndex('schedule_periods_status_index');
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_periods', function (Blueprint $table) {
            $table->string('status', 16)->default('frozen');
            $table->index('status');
        });
    }
};
