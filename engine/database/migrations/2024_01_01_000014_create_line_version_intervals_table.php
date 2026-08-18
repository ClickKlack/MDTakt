<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Beobachtete Gültigkeit einer Linien-Version (FAHRPLANPERIODEN §5.4 b).
        //
        // Der GTFS-Feed schneidet an beiden Rändern ab — im Import vom 17.08.2026 begannen
        // 19 von 58 Wochenmustern exakt am Fensteranfang. Eine Grenze gilt deshalb nur als
        // gesichert, wenn der Wechsel INNERHALB eines Fensters beobachtet wurde; sonst ist
        // sie eine bloße Untergrenze ("mindestens seit/bis") und kann von einem späteren
        // Import verdichtet werden.
        Schema::create('line_version_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_version_id')->constrained('line_versions')->cascadeOnDelete();
            $table->date('valid_from');
            $table->date('valid_to');
            $table->boolean('from_confirmed')->default(false);
            $table->boolean('to_confirmed')->default(false);
            $table->timestampsTz();

            $table->index(['line_version_id']);
            $table->index(['valid_from', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_version_intervals');
    }
};
