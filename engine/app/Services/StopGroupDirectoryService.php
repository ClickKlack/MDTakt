<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StopGroup;
use Illuminate\Support\Facades\DB;

/**
 * Das Haltestellen-Verzeichnis für die Pflege: je Haltestelle ihre Halte, ob dort Fahrten
 * beginnen oder enden, und welche Linien sie berühren.
 *
 * Die Endpunkt-Angabe ist hier keine Zierde — sie ist das Auswahlkriterium. Am Realbestand
 * sind nur **104 von 631** Halten überhaupt Anfang oder Ende einer Fahrt; an allen übrigen
 * kann die Umlauf-Pflege nichts tun, und eine Auswahlliste, die sie gleichwertig anbietet,
 * führt in die Irre (so geschehen bei „Bahnhof Herrenkrug", das zweimal erscheint und an
 * beiden Punkten nur durchfahren wird).
 */
final class StopGroupDirectoryService
{
    public function __construct(
        private readonly ConsolidatedStopNameResolver $stopNames,
        private readonly StopGroupService $groups,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function overview(?string $suche = null, bool $nurEndpunkte = false): array
    {
        $this->groups->sync();

        $mitglieder = DB::table('stop_group_members as m')
            ->join('consolidated_stops as s', 's.id', '=', 'm.consolidated_stop_id')
            ->select('m.stop_group_id', 'm.consolidated_stop_id', 'm.assigned_via', 's.anchor_lat', 's.anchor_lon')
            ->get()
            ->groupBy('stop_group_id');

        $namen = $this->stopNames->namesForAll();
        $endpunkte = $this->terminusInfo();

        $ergebnis = [];

        foreach (StopGroup::query()->orderBy('name')->get() as $gruppe) {
            $eigene = $mitglieder->get($gruppe->id, collect());

            $halte = $eigene->map(static fn (object $m): array => [
                'id' => (int) $m->consolidated_stop_id,
                'name' => $namen[$m->consolidated_stop_id] ?? '—',
                'assigned_via' => $m->assigned_via,
                'lat' => (float) $m->anchor_lat,
                'lon' => (float) $m->anchor_lon,
                'ending_lines' => $endpunkte[(int) $m->consolidated_stop_id]['ending'] ?? [],
                'starting_lines' => $endpunkte[(int) $m->consolidated_stop_id]['starting'] ?? [],
            ])->values()->all();

            $endend = $this->linesOf($halte, 'ending_lines');
            $beginnend = $this->linesOf($halte, 'starting_lines');

            $ergebnis[] = [
                'id' => $gruppe->id,
                'name' => $gruppe->name,
                'created_via' => $gruppe->created_via->value,
                'note' => $gruppe->note,
                'stop_count' => count($halte),
                'stops' => $halte,
                'ending_lines' => $endend,
                'starting_lines' => $beginnend,
                'is_terminus' => $endend !== [] || $beginnend !== [],
                // Eine Haltestelle, an der nur endet oder nur beginnt, ist der Hinweis auf ein
                // fehlendes Gegenstück — genau der Fall, den der Zuordnungs-Editor lösen soll.
                'one_sided' => ($endend === []) !== ($beginnend === []),
                'manual_count' => count(array_filter($halte, static fn (array $h): bool => $h['assigned_via'] === 'manual')),
            ];
        }

        if ($nurEndpunkte) {
            $ergebnis = array_values(array_filter($ergebnis, static fn (array $g): bool => $g['is_terminus']));
        }

        if ($suche !== null && $suche !== '') {
            $begriff = mb_strtolower($suche);
            $ergebnis = array_values(array_filter($ergebnis, static function (array $g) use ($begriff): bool {
                if (str_contains(mb_strtolower($g['name']), $begriff)) {
                    return true;
                }

                foreach ($g['stops'] as $halt) {
                    if (str_contains(mb_strtolower($halt['name']), $begriff)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        return $ergebnis;
    }

    /**
     * Welche Linien enden bzw. beginnen je Halt — über alle Perioden und Fahrplantypen
     * hinweg. Für die Auswahl genügt „hier passiert überhaupt etwas"; die perioden- und
     * typgenaue Sicht liefert erst der Editor selbst.
     *
     * @return array<int, array{ending: array<int, string>, starting: array<int, string>}>
     */
    private function terminusInfo(): array
    {
        $info = [];

        foreach (['last_stop_id' => 'ending', 'first_stop_id' => 'starting'] as $spalte => $schluessel) {
            $zeilen = DB::table('consolidated_trips as ct')
                ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
                ->whereNotNull('ct.'.$spalte)
                ->select('ct.'.$spalte.' as stop_id', 'lv.line')
                ->distinct()
                ->get();

            foreach ($zeilen as $zeile) {
                $info[(int) $zeile->stop_id][$schluessel][] = $zeile->line;
            }
        }

        foreach ($info as $stopId => $seiten) {
            foreach (['ending', 'starting'] as $schluessel) {
                $linien = array_values(array_unique($seiten[$schluessel] ?? []));
                sort($linien, SORT_NATURAL);
                $info[$stopId][$schluessel] = $linien;
            }
        }

        return $info;
    }

    /**
     * @param  array<int, array<string, mixed>>  $halte
     * @return array<int, string>
     */
    private function linesOf(array $halte, string $schluessel): array
    {
        $linien = [];

        foreach ($halte as $halt) {
            foreach ($halt[$schluessel] as $linie) {
                $linien[] = $linie;
            }
        }

        // Nicht über Array-Schlüssel deduplizieren: PHP macht aus dem Schlüssel "10" die
        // Zahl 10, und die Linie käme als Zahl aus der API — anders als überall sonst, wo
        // `line` ein String ist (die Linien heißen auch "N1").
        $liste = array_values(array_unique($linien));
        sort($liste, SORT_NATURAL);

        return $liste;
    }
}
