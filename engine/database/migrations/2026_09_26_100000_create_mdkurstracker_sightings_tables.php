<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sichtungs-Eingang aus MDKursTracker (INTEGRATION_MDKURSTRACKER §5.1).
     *
     * Ersetzt das Gerüst `sightings` aus I-01. Das hing mit `assigned_trip_id` an den Roh-Trips,
     * deren gtfs.de-IDs jeder Import neu vergibt — eine Zuordnung dorthin überlebte keine Woche.
     * Die Tabelle war nie beschrieben, sie wird deshalb verworfen statt umgebaut.
     */
    public function up(): void
    {
        Schema::dropIfExists('sightings');

        // Der Laufweg einer Tracker-Fahrt, einmal je Fingerprint. Er wird aufbewahrt, weil eine
        // Sichtung nach dem nächsten GTFS-Import erneut zugeordnet werden muss — der Tracker kennt
        // einen geänderten Laufweg über HAFAS oft eine Woche früher als der Feed.
        Schema::create('mdkt_routes', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 128)->unique();
            $table->unsignedBigInteger('mdkt_trip_id')->nullable();
            $table->string('line', 8);
            $table->string('direction')->nullable();
            // [{seq, hafas_stop_id, stop_name, line, arrival_planned, departure_planned}] — UTC
            $table->json('stops');
            $table->timestampsTz();
        });

        Schema::create('sightings', function (Blueprint $table) {
            $table->id();
            // Idempotenz: Der Tracker sendet nach einem Fehlschlag erneut, ohne zu wissen, was
            // schon angekommen ist.
            $table->unsignedBigInteger('mdkt_recording_id')->unique();
            $table->foreignId('mdkt_route_id')->constrained('mdkt_routes')->cascadeOnDelete();
            $table->string('line', 8);           // Linie am gesichteten Halt
            $table->string('course_number', 8);  // Nutzereingabe im Tracker, ohne Linien-Präfix
            $table->string('hafas_stop_id', 32);
            $table->string('stop_name')->nullable();
            $table->date('service_date');        // Betriebstag laut Tracker (Europe/Berlin)
            $table->timestampTz('observed_at');
            $table->timestampTz('departure_planned');
            $table->timestampTz('departure_actual')->nullable();

            // Ergebnis der Zuordnung. Die Fahrt kann durch einen Import wegfallen — dann wird
            // die Spalte genullt und die Sichtung beim nächsten Neu-Zuordnen wieder aufgegriffen.
            $table->char('trip_signature', 64)->nullable();
            $table->foreignId('consolidated_trip_id')->nullable()->constrained('consolidated_trips')->nullOnDelete();
            $table->string('match', 24);          // App\Enums\SightingMatch
            $table->unsignedSmallInteger('match_attempts')->default(0);

            $table->string('status', 16);         // App\Enums\SightingStatus
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'service_date']);
            $table->index('consolidated_trip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sightings');
        Schema::dropIfExists('mdkt_routes');

        // Das alte Gerüst zurück, damit der Rückweg die Migration aus I-01 wieder trifft.
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
};
