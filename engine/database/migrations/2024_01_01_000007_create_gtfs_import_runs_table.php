<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gtfs_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status'); // running | success | failed (siehe GtfsImportStatus-Enum)
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            // Feed-Datenstand aus feed_info.txt
            $table->string('feed_version')->nullable();
            $table->date('feed_start_date')->nullable();
            $table->date('feed_end_date')->nullable();

            // Anzahl verarbeiteter Zeilen je Tabelle als JSON-Objekt
            $table->jsonb('counts')->nullable();
            $table->text('error_message')->nullable();

            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gtfs_import_runs');
    }
};
