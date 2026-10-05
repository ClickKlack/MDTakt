<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nachtrag für den Bestand vor K10 (KURSE §2 K10, ROADMAP I-16): Anschlüsse, an denen das
 * Fahrzeug am selben Halt weiterfährt, als Durchlauf kennzeichnen.
 *
 * **Eine geprüfte Liste, keine Regel.** Ausgewertet am Produktivbestand vom 05.10.2026: An elf
 * Haltestellen beginnt die Folgefahrt an dem Halt, an dem die Ankunft endet. Neun davon sind
 * nach Rückfrage Durchläufe — Tauschpunkte (City Carré, Listemannstraße), Linienwechsel im
 * Nachtnetz (Allee-Center N1↔N2, Am Stadtblick 69→N7), Ringe (Hbf 1→1 und 8→8, Listemannstraße
 * 48→48) und geteilte Fahrten derselben Linie. Zwei sind echte Wenden und fehlen hier bewusst:
 * Leipziger Chaussee (3→3 mit 4–24 Min, 9→9) und Klusweg (71→71 nach 6 Min).
 *
 * Wirkt nur auf den Bestand zum Zeitpunkt des Laufs. Was danach entsteht, setzt der Pflegende
 * selbst (Schalter beim Anlegen, Tauschpunkt-Lauf, Chip am Anschluss).
 */
return new class extends Migration
{
    /** Haltestellen nach Anzeigename — die Ids unterscheiden sich zwischen Umgebungen. */
    private const HALTESTELLEN = [
        'City Carré',
        'Listemannstraße',
        'Allee - Center',
        'Am Stadtblick',
        'Benediktinerstr./Ges.-Haus',
        'Kastanienstraße',
        'Braunlager Straße',
        'Hbf / Willy-Brandt-Platz',
        'Olvenstedter Platz',
    ];

    public function up(): void
    {
        $ids = DB::table('trip_links as tl')
            ->join('consolidated_trips as nach', 'nach.id', '=', 'tl.to_trip_id')
            ->join('stop_group_members as m', 'm.consolidated_stop_id', '=', 'tl.stop_id')
            ->join('stop_groups as g', 'g.id', '=', 'm.stop_group_id')
            ->whereIn('g.name', self::HALTESTELLEN)
            ->where('tl.kind', 'link')
            ->where('tl.through_run', false)
            ->whereColumn('nach.first_stop_id', 'tl.stop_id')
            ->pluck('tl.id')
            ->all();

        $anzahl = 0;

        foreach (array_chunk($ids, 1000) as $teil) {
            $anzahl += DB::table('trip_links')
                ->whereIn('id', $teil)
                ->update(['through_run' => true, 'updated_at' => now()]);
        }

        Log::info('Existing trip links marked as through runs', ['count' => $anzahl]);
    }

    /**
     * Bewusst ohne Rücknahme: Danach gesetzte oder umgeschaltete Durchläufe ließen sich nicht von
     * diesem Nachtrag unterscheiden. Die Spalte selbst entfernt die vorige Migration.
     */
    public function down(): void {}
};
