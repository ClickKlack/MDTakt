<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Enums\RouteType;
use App\Models\StopGroup;
use App\Support\DayRange;
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
        private readonly TripLinkValidity $validity,
    ) {}

    /**
     * @param  int|null  $periodId  Mit Periode **und** Fahrplantyp tragen die Zeilen zusätzlich
     *                              `modes` und `open` — die Arbeitsliste des Anschlusseditors.
     * @return array<int, array<string, mixed>>
     */
    public function overview(
        ?string $suche = null,
        bool $nurEndpunkte = false,
        ?int $periodId = null,
        ?FahrplanTyp $dayType = null,
    ): array {
        $this->groups->sync();

        $ausschnitt = $periodId !== null && $dayType !== null
            ? $this->workloadFor($periodId, $dayType)
            : [];

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
                // Nur mit Periode und Fahrplantyp belegt: Welche Verkehrsmittel hier in diesem
                // Ausschnitt beginnen oder enden, und wie viel davon noch offen ist.
                'modes' => $ausschnitt[$gruppe->id]['modes'] ?? [],
                'open' => $ausschnitt[$gruppe->id]['open'] ?? null,
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
     * Die Arbeitslast je Haltestelle: welche Verkehrsmittel hier vorkommen und wie viele
     * Fahrten davon noch keine Entscheidung tragen.
     *
     * **Über die ganze Periode gezählt, nicht je Versionsstand.** Der Stand entsteht erst beim
     * Aufbau eines einzelnen Boards, samt Faltung der beteiligten Linien-Versionen; ihn für
     * alle 60 Endstellen zu rechnen hieße, das 60-mal zu tun und bei jedem Standwechsel erneut.
     * Für die Frage, die diese Liste beantwortet — *ist hier noch etwas zu tun?* — trägt die
     * gröbere Zählung: Sie ist nie fälschlich null. Sie kann höher liegen als die Zahl im
     * Editor, wenn dort ein Stand gewählt ist, der nur einen Teil der Periode abdeckt.
     *
     * Eine Fahrt, die hier endet **und** beginnt (Wendeschleife), zählt zweimal — genau wie im
     * Board, das sie in beiden Spalten führt.
     *
     * @return array<int, array{modes: array<int, string>, open: array<string, int>}>
     */
    private function workloadFor(int $periodId, FahrplanTyp $dayType): array
    {
        $roh = [];

        foreach (['last_stop_id' => 'from_trip_id', 'first_stop_id' => 'to_trip_id'] as $spalte => $fremd) {
            $zeilen = DB::table('consolidated_trips as ct')
                ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
                ->join('stop_group_members as m', 'm.consolidated_stop_id', '=', 'ct.'.$spalte)
                ->where('lv.period_id', $periodId)
                ->where('lv.day_type', $dayType->value)
                ->select(['m.stop_group_id', 'ct.id', 'ct.route_type', 'ct.line_version_id'])
                ->get();

            $kanten = $this->linksBySide($zeilen->pluck('id')->all(), $fremd);

            foreach ($zeilen as $zeile) {
                $mittel = RouteType::modeFor((int) $zeile->route_type);
                $id = (int) $zeile->stop_group_id;

                // Auch ein Verkehrsmittel ohne offene Fahrt gehört in die Liste — sonst
                // verschwände eine fertig gepflegte Haltestelle aus der Auswahl.
                $roh[$id]['__mittel'][$mittel] = true;
                $roh[$id][$mittel] ??= 0;

                if ($this->stillOpen((int) $zeile->id, (int) $zeile->line_version_id, $kanten)) {
                    $roh[$id][$mittel]++;
                }
            }
        }

        $ergebnis = [];

        foreach ($roh as $id => $werte) {
            $mittel = array_keys($werte['__mittel']);
            sort($mittel);

            $offen = ['total' => 0];

            foreach ($mittel as $m) {
                $offen[$m] = $werte[$m] ?? 0;
                $offen['total'] += $offen[$m];
            }

            $ergebnis[$id] = ['modes' => $mittel, 'open' => $offen];
        }

        return $ergebnis;
    }

    /**
     * Ist an dieser Seite der Fahrt noch etwas zu tun?
     *
     * **Nicht** schlicht „hängt eine Zeile dran". Seit eine Fahrt mehrere Anschlüsse tragen
     * darf, deckt ein einzelner womöglich nur einen Teil ihrer Tage ab: Wechselt eine
     * Nachbarlinie mitten in der Periode die Version, braucht dieselbe Fahrt ab dem Wechseltag
     * einen zweiten. Nach der alten Zählung sähe sie fertig aus, und die Haltestelle
     * verschwände aus der Arbeitsliste — genau die Lücke, die diese Liste schließen soll.
     *
     * @param  array<int, array<int, array{0: int|null, 1: int|null}>>  $kanten
     */
    private function stillOpen(int $tripId, int $versionId, array $kanten): bool
    {
        $offen = $this->validity->forVersion($versionId);

        foreach ($kanten[$tripId] ?? [] as $kante) {
            $offen = DayRange::subtract($offen, $this->validity->forLink($kante[0], $kante[1]));

            if ($offen === []) {
                return false;
            }
        }

        return $offen !== [];
    }

    /**
     * Die Zeilen je Fahrt, gesammelt über eine Seite (`from_trip_id` oder `to_trip_id`).
     *
     * @param  array<int, mixed>  $tripIds
     * @return array<int, array<int, array{0: int|null, 1: int|null}>>
     */
    private function linksBySide(array $tripIds, string $spalte): array
    {
        $ids = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $tripIds)));

        if ($ids === []) {
            return [];
        }

        $ergebnis = [];

        foreach (array_chunk($ids, 5000) as $teil) {
            foreach (DB::table('trip_links')->whereIn($spalte, $teil)->get(['from_trip_id', 'to_trip_id', $spalte]) as $zeile) {
                $ergebnis[(int) $zeile->{$spalte}][] = [
                    $zeile->from_trip_id === null ? null : (int) $zeile->from_trip_id,
                    $zeile->to_trip_id === null ? null : (int) $zeile->to_trip_id,
                ];
            }
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
