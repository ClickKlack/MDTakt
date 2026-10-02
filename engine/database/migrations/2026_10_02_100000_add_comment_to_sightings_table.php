<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notiz des Erfassers an der Sichtung (recordings.comment im Tracker).
 *
 * Freitext, kann Personenbezug haben — wird nur im Admin ausgegeben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sightings', function (Blueprint $table): void {
            $table->string('comment', 500)->nullable()->after('departure_actual');
        });
    }

    public function down(): void
    {
        Schema::table('sightings', function (Blueprint $table): void {
            $table->dropColumn('comment');
        });
    }
};
