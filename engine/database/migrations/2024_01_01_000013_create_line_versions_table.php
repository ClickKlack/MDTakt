<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ein Fahrplanstand einer Linie für einen Betriebstag-Typ (FAHRPLANPERIODEN §4.2).
        // Die Version IST ihr Fingerprint — kehrt eine Linie nach einer Baustelle zum alten
        // Fahrplan zurück, bekommt die bestehende Version ein weiteres Gültigkeits-Intervall
        // statt einer inhaltlich identischen neuen Version (§5.4 a).
        //
        // Linien-Schlüssel ist route_short_name ohne route_type: fährt eine Linie zeitweise
        // als Bus statt als Tram (Ersatzverkehr), ist das eine eigene Version derselben Linie.
        Schema::create('line_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('schedule_periods')->cascadeOnDelete();
            $table->string('line');           // route_short_name
            $table->string('day_type', 16);   // App\Enums\FahrplanTyp
            $table->unsignedInteger('version_no'); // fortlaufend je (Periode, Linie, Typ), nur Anzeige
            $table->char('fingerprint', 64);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            $table->unique(['period_id', 'line', 'day_type', 'fingerprint']);
            $table->index(['period_id', 'line', 'day_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_versions');
    }
};
