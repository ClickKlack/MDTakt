<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Brücke von der volatilen gtfs.de-trip_id zur stabilen Fahrt-Identität
        // (FAHRPLANPERIODEN §5.1/§6.1). Wird bei jedem Import neu aufgebaut.
        //
        // Geschlüsselt auf (trip_id, day_type): Ein "täglich"-Service gehört zu allen vier
        // Fahrplantypen, und der Typ steckt in der Signatur — ein solcher Trip bekommt
        // also mehrere Zeilen.
        Schema::create('trip_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('trip_id');
            $table->string('day_type', 16);   // App\Enums\FahrplanTyp
            $table->char('signature', 64);    // SHA256(line | day_type | HH:MM-Sequenz)
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('trip_id')->references('trip_id')->on('trips')->cascadeOnDelete();
            $table->unique(['trip_id', 'day_type']);
            $table->index('signature');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_signatures');
    }
};
