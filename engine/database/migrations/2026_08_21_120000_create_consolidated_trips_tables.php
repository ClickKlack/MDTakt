<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eine Fahrt des Konsolidats, identifiziert über ihre Signatur innerhalb einer
        // Linien-Version (FAHRPLANPERIODEN §6). Anders als die Roh-Trips überlebt sie den
        // nächsten Import: Die volatile gtfs.de-trip_id kommt hier nicht mehr vor.
        Schema::create('consolidated_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_version_id')->constrained('line_versions')->cascadeOnDelete();
            $table->char('signature', 64);
            // Das Verkehrsmittel ist Attribut der Fahrt, nicht Teil des Linien-Schlüssels
            // (entschieden 18.08.2026). Fährt eine Linie zeitweise als Bus statt als Tram,
            // steht das hier — die Linie bleibt dieselbe.
            $table->unsignedSmallInteger('route_type');
            $table->foreignId('first_stop_id')->nullable()->constrained('consolidated_stops')->nullOnDelete();
            $table->foreignId('last_stop_id')->nullable()->constrained('consolidated_stops')->nullOnDelete();

            // Bewusst KEIN unique(line_version_id, signature): Im Realbestand tragen 494
            // Signatur/Typ-Paare mehrere Trips — dieselbe Linie fährt dieselbe Zeitsequenz
            // unter verschiedenen Service-Mustern. Fielen zwei davon auf denselben Tag,
            // verschluckte ein Unique-Constraint eine echte Fahrt. Idempotent bleibt der
            // Lauf trotzdem: Eine Version wird immer vollständig ersetzt, nie ergänzt.
            $table->index(['line_version_id', 'signature']);
        });

        Schema::create('consolidated_stop_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_trip_id')->constrained('consolidated_trips')->cascadeOnDelete();
            $table->foreignId('stop_id')->constrained('consolidated_stops')->cascadeOnDelete();
            $table->unsignedSmallInteger('stop_sequence');
            // GTFS-Lokalzeit als Text, kann über 24:00 hinausgehen ("25:10:00") — wie in
            // der Roh-Tabelle, damit der Betriebstag nicht am Kalendertag zerbricht.
            $table->string('arrival_time', 8)->nullable();
            $table->string('departure_time', 8)->nullable();

            $table->unique(['consolidated_trip_id', 'stop_sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidated_stop_times');
        Schema::dropIfExists('consolidated_trips');
    }
};
