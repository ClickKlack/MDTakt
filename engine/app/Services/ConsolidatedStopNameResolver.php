<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Löst Halt-Identitäten zu Namen auf.
 *
 * Ein Halt trägt seinen Namen als Attribut-Historie (`consolidated_stop_versions`), weil Namen
 * sich ändern, ohne dass der Halt ein anderer wird. Für die Anzeige gilt „latest wins" (§5.1):
 * die jüngste beobachtete Version gewinnt.
 *
 * **Bekannte Vereinfachung:** Für eine historische Fahrplan-Version wäre streng genommen der
 * Name richtig, der am damaligen Tag galt — nicht der heutige. Das bleibt bewusst offen,
 * solange im Realbestand genau ein Halt überhaupt zwei Namensversionen trägt. Sollte sich das
 * ändern, ist hier der Ort dafür.
 */
final class ConsolidatedStopNameResolver
{
    /**
     * Namen zu genau den angefragten Halten.
     *
     * @param  array<int, int>  $stopIds
     * @return array<int, string>
     */
    public function namesFor(array $stopIds): array
    {
        $eindeutig = array_values(array_unique(array_filter($stopIds)));

        if ($eindeutig === []) {
            return [];
        }

        $namen = [];

        foreach (array_chunk($eindeutig, 1000) as $teil) {
            $namen += $this->query()->whereIn('v.consolidated_stop_id', $teil)->get()
                ->keyBy('consolidated_stop_id')
                ->map(static fn (object $r): string => $r->name)
                ->all();
        }

        return $namen;
    }

    /**
     * Namen aller Halte. Nur dort verwenden, wo die Menge ohnehin unbegrenzt ist —
     * bei 624 Identitäten unkritisch, aber `namesFor()` ist die genauere Wahl.
     *
     * @return array<int, string>
     */
    public function namesForAll(): array
    {
        return $this->query()->get()
            ->keyBy('consolidated_stop_id')
            ->map(static fn (object $r): string => $r->name)
            ->all();
    }

    /**
     * Aufsteigend nach `valid_to` sortiert — beim anschließenden `keyBy` überschreibt der
     * jüngste Eintrag die älteren, womit „latest wins" ohne Fensterfunktion auskommt.
     */
    private function query(): Builder
    {
        return DB::table('consolidated_stop_versions as v')
            ->orderBy('v.consolidated_stop_id')
            ->orderBy('v.valid_to')
            ->select('v.consolidated_stop_id', 'v.name');
    }
}
