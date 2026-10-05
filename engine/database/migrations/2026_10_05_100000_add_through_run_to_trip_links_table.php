<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durchlauf am Tauschpunkt (KURSE §2 K10): Das Fahrzeug verlässt den Halt nicht, nur die
 * Liniennummer wechselt. Ein Merkmal am Anschluss, keine neue Art — nur bei `kind = link`.
 *
 * Vorgabe `false`: Der Bestand gilt als Wende, bis er nachgetragen wird
 * (`trip-links:mark-through-runs`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_links', function (Blueprint $table): void {
            $table->boolean('through_run')->default(false)->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('trip_links', function (Blueprint $table): void {
            $table->dropColumn('through_run');
        });
    }
};
