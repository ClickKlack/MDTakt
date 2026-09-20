<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Die Umlauf-Ebene (KURSE §3). GTFS liefert sie nicht: `trips.block_id` ist im
        // gesamten gtfs.de-Feed NULL, `direction_id` ebenso. Sie entsteht ausschließlich
        // durch Pflege — und später durch Sichtungen, die in dieses Gefüge hineinlaufen.
        //
        // Angehängt wird an `consolidated_trips.id`, nicht an der Fahrt-Signatur. Die
        // Signatur wäre der naheliegende Kandidat, weil sie Importe überlebt — sie ist je
        // Version aber **nicht eindeutig** (494 Signatur/Typ-Paare im Realbestand tragen
        // mehrere Fahrten). Die ID trägt stattdessen: `TripConsolidationService::fillVersion()`
        // schreibt eine Version nur, solange sie unvollständig ist, und steigt sonst aus.
        Schema::create('trip_links', function (Blueprint $table) {
            $table->id();

            // Beide nullable: Eine Kette darf bewusst ohne Vorgänger beginnen (Ausrücken)
            // oder ohne Nachfolger enden (Einrücken) — der Betriebsfahrt-Fall.
            $table->foreignId('from_trip_id')->nullable()->constrained('consolidated_trips')->cascadeOnDelete();
            $table->foreignId('to_trip_id')->nullable()->constrained('consolidated_trips')->cascadeOnDelete();
            $table->foreignId('stop_id')->constrained('consolidated_stops')->cascadeOnDelete();

            // `link` | `start` | `end` — App\Enums\TripLinkKind. Aus den NULL-Spalten
            // ableitbar und trotzdem gespeichert: Es macht Abfragen und Absicht lesbar.
            $table->string('kind', 8);
            $table->string('note')->nullable();
            $table->timestampsTz();

            // Diese beiden Constraints *sind* die Fachregel: Ein Fahrzeug hat höchstens
            // einen Nachfolger und höchstens einen Vorgänger. NULL gilt in SQL als ungleich
            // zu NULL — in PostgreSQL wie in SQLite —, beliebig viele Fahrten dürfen also
            // „ohne Vorgänger" sein, ohne sich gegenseitig zu blockieren.
            $table->unique('from_trip_id');
            $table->unique('to_trip_id');

            $table->index('stop_id');
        });

        // Der Umlauf als benennbare Einheit. Die Kursnummer gehört ihm, nicht der Linie:
        // Wechselt ein Fahrzeug in Sudenburg von der 1 auf die 13, bleibt die Nummer und
        // nur der Anzeige-Präfix wechselt (KURSE §2, K1).
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('schedule_periods')->cascadeOnDelete();
            $table->string('day_type', 16);  // App\Enums\FahrplanTyp
            $table->string('number', 8);     // Kursnummer ohne Linien-Präfix, z. B. "03"
            $table->string('note')->nullable();
            $table->timestampsTz();

            // Bewusst KEIN unique auf (period_id, day_type, number): Ob die Kursnummer
            // netzweit eindeutig ist oder nur je Linie, ist offen (KURSE §2, K3). Dubletten
            // werden gemeldet, nicht verhindert — ein Index lässt sich später nachziehen,
            // ein zu früh gesetzter blockiert dagegen echte Fälle.
            $table->index(['period_id', 'day_type', 'number']);
        });

        Schema::create('course_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            // Eine Fahrt gehört zu höchstens einem Umlauf. Die Zuweisung erfolgt immer für
            // die ganze Kette, nie für eine einzelne Fahrt.
            $table->foreignId('consolidated_trip_id')->unique()->constrained('consolidated_trips')->cascadeOnDelete();
            $table->timestampsTz();
        });

        // Der Haltestellen-Editor fragt genau auf diesen beiden Spalten ab („welche Fahrten
        // enden hier, welche beginnen hier"). PostgreSQL legt für Fremdschlüssel keinen
        // Index an — ohne die beiden liefe die Abfrage auf einen Seq Scan über alle Fahrten.
        Schema::table('consolidated_trips', function (Blueprint $table) {
            $table->index('first_stop_id');
            $table->index('last_stop_id');
        });
    }

    public function down(): void
    {
        Schema::table('consolidated_trips', function (Blueprint $table) {
            $table->dropIndex(['first_stop_id']);
            $table->dropIndex(['last_stop_id']);
        });

        Schema::dropIfExists('course_trips');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('trip_links');
    }
};
