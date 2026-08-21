<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Systemvorschlag für einen Periodenwechsel (FAHRPLANPERIODEN §4.3): Ändern sich an
        // einem Tag sehr viele Linien gleichzeitig, ist das ein Indiz für einen echten
        // Fahrplanwechsel statt für einzelne Baustellen. Das System legt die Periode NICHT
        // selbst an — es bietet sie an, der Admin entscheidet.
        Schema::create('period_change_offers', function (Blueprint $table) {
            $table->id();
            $table->date('suggested_from');              // beobachteter Wechseltag
            $table->unsignedInteger('changed_line_count');
            $table->unsignedInteger('active_line_count'); // Linien, die an dem Tag überhaupt fahren
            $table->jsonb('lines');                       // betroffene route_short_name
            $table->string('status', 16);                 // App\Enums\PeriodOfferStatus
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            // Ein Tag wird höchstens einmal vorgeschlagen — ein abgelehnter Vorschlag darf bei
            // jedem Folge-Import nicht erneut auftauchen.
            $table->unique('suggested_from');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_change_offers');
    }
};
