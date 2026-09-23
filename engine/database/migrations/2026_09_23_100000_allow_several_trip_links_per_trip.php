<?php

declare(strict_types=1);

use App\Services\TripLinkValidity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Eine Fahrt darf mehrere Anschlüsse tragen — solange sie an **verschiedenen Tagen** gelten.
     *
     * Die beiden Unique-Constraints trugen bisher die Fachregel „ein Fahrzeug hat höchstens
     * einen Nachfolger". Das stimmt an *einem Tag*, aber eine Fahrt lebt über viele Tage, und
     * die fallen in verschiedene Versionsstände.
     *
     * Der Fall aus dem Bestand: Fahrt 27886 (Linie 2, Version 1) gilt vom 21.09. bis 15.10.
     * Sie ist mit einer Fahrt der Linie 13 Version 1 verknüpft, die nur bis zum 09.10. gilt. An
     * den sechs Tagen danach fährt dasselbe Fahrzeug weiter — auf die 13 der **neuen** Version,
     * die daneben unentschieden stand und sich nicht verknüpfen ließ, weil die Unique-Regel den
     * einen erlaubten Anschluss schon vergeben sah.
     *
     * An die Stelle des Constraints tritt eine Überschneidungsprüfung in
     * {@see TripLinkValidity}: Zwei Anschlüsse an derselben Fahrt sind erlaubt,
     * wenn ihre Gültigkeiten sich an keinem Tag berühren. Die Fachregel gilt damit unverändert —
     * nur eben je Tag statt je Fahrt.
     *
     * **Warum die Datenbank das nicht selbst erzwingen kann:** Die Gültigkeit eines Anschlusses
     * ist der Schnitt der beobachteten Intervalle beider Linien-Versionen. Das steht in
     * `line_version_intervals`, je Version mehrfach, und ändert sich mit jedem Import. Ein
     * Ausschluss-Constraint darüber wäre an die Intervalle gekoppelt und bräche beim nächsten
     * Feed. Die Prüfung gehört deshalb dorthin, wo sie erklärbar ist.
     */
    public function up(): void
    {
        Schema::table('trip_links', function (Blueprint $table) {
            $table->dropUnique(['from_trip_id']);
            $table->dropUnique(['to_trip_id']);

            // Gesucht wird weiterhin über beide Spalten — die Eindeutigkeit fällt weg, der
            // Zugriffspfad nicht.
            $table->index('from_trip_id');
            $table->index('to_trip_id');
        });
    }

    public function down(): void
    {
        Schema::table('trip_links', function (Blueprint $table) {
            $table->dropIndex(['from_trip_id']);
            $table->dropIndex(['to_trip_id']);

            // Schlägt fehl, sobald eine Fahrt mehrere Anschlüsse trägt — das ist gewollt: Der
            // Rückweg darf die Pflege nicht stillschweigend halbieren.
            $table->unique('from_trip_id');
            $table->unique('to_trip_id');
        });
    }
};
