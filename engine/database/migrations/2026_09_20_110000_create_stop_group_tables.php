<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Die **Haltestelle** als Betriebspunkt — sie fasst die Halte zusammen, die im Betrieb
        // derselbe Ort sind, üblicherweise die Richtungs-Bahnsteige (KURSE §3.1).
        //
        // Warum das nötig ist: Die Konsolidierung verschmilzt Halte nur bei ≤ 12 m und gleichem
        // Namen (FAHRPLANPERIODEN §5.1) — richtig für die Halt-Identität, aber zu eng für den
        // Betrieb. Am Realbestand sind **64 von 104 Endstellen einseitig**: An „Herrenkrug" 391
        // enden Fahrten, an „Herrenkrug" 250 (72 m entfernt) beginnen sie. Ohne diese Klammer
        // ließe sich an über der Hälfte aller Fahrt-Endpunkte kein Anschluss bilden.
        Schema::create('stop_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Schlüssel der automatischen Gruppierung (normalisierter Name). NULL bei einer
            // rein manuell angelegten Gruppe — die trägt keinen Automatik-Anspruch mehr.
            $table->string('name_key')->nullable();
            $table->string('created_via', 8);  // App\Enums\StopGroupOrigin
            $table->string('note')->nullable();
            $table->timestampsTz();

            $table->index('name_key');
        });

        Schema::create('stop_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stop_group_id')->constrained('stop_groups')->cascadeOnDelete();
            // Ein Halt gehört zu genau einer Haltestelle.
            $table->foreignId('consolidated_stop_id')->unique()->constrained('consolidated_stops')->cascadeOnDelete();
            // `manual` schützt die Zuordnung vor der Automatik: Ein von Hand zugeordneter Halt
            // wird beim nächsten Lauf nicht zurück in seine Namensgruppe gezogen. Ohne diese
            // Unterscheidung wäre jede Pflege beim nächsten Import wieder weg.
            $table->string('assigned_via', 8);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stop_group_members');
        Schema::dropIfExists('stop_groups');
    }
};
