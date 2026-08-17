<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Schulferien (Sachsen-Anhalt) — amtlich, jährlich anders, nicht berechenbar → im Admin gepflegt.
        // Dient der Fahrplantyp-Klassifikation (Werktag in Ferien = „Mo-Fr Ferien").
        Schema::create('school_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestampsTz();

            $table->index('start_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_holidays');
    }
};
