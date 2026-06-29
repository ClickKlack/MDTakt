<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Im Admin pflegbare Linienfarben. Geschlüsselt auf route_short_name (stabile
        // Linienbezeichnung), nicht auf die volatile GTFS-route_id — überlebt Re-Importe.
        Schema::create('line_colors', function (Blueprint $table) {
            $table->string('route_short_name')->primary();
            $table->string('color', 7); // Hex inkl. '#', z. B. #c9346c
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_colors');
    }
};
