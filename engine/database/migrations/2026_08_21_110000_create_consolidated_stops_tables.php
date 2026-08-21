<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ein physischer Halt — global, nicht je Periode (entschieden 18.08.2026).
        // Identität = Koordinaten + normalisierter Name (Schwelle 12 m, entschieden
        // 21.08.2026, FAHRPLANPERIODEN §5.1). Der Anker bleibt die erste Sichtung;
        // Umbenennungen und Verlegungen liegen in consolidated_stop_versions, damit die
        // Identität nicht bei jeder Namensänderung zerfällt.
        Schema::create('consolidated_stops', function (Blueprint $table) {
            $table->id();
            $table->decimal('anchor_lat', 10, 7);
            $table->decimal('anchor_lon', 10, 7);
            $table->string('name_key');  // normalisierter Name, Teil der Identität
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            // Kandidatensuche läuft über eine Bounding-Box auf diesen beiden Spalten,
            // die genaue Distanz danach in PHP (Haversine).
            $table->index(['name_key', 'anchor_lat', 'anchor_lon']);
        });

        // Attribut-Historie eines Halts: Name und exakte Lage gelten jeweils für einen
        // beobachteten Zeitraum. Die Grenzen sind Beobachtungen, keine Behauptungen (§5.4 b):
        // stops.txt trägt kein Datum, beobachtet wird immer nur das Feed-Fenster.
        Schema::create('consolidated_stop_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_stop_id')->constrained('consolidated_stops')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);
            $table->date('valid_from');
            $table->date('valid_to');
            $table->boolean('from_confirmed')->default(false);
            $table->boolean('to_confirmed')->default(false);

            $table->index(['consolidated_stop_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidated_stop_versions');
        Schema::dropIfExists('consolidated_stops');
    }
};
