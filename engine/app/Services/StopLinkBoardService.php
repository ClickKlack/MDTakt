<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Enums\RouteType;
use App\Enums\TripLinkKind;
use App\Models\SchedulePeriod;
use App\Models\StopGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Die Datengrundlage des Haltestellen-Editors: Welche Fahrten enden an einem Halt, welche
 * beginnen dort, und was ist bereits entschieden (KURSE §1).
 *
 * **Warum es den Versionsstand gibt.** Der Editor arbeitet auf Periode und Fahrplantyp, nicht
 * auf einem Datum (KURSE §2 K5). Innerhalb einer Periode kann eine Linie aber mehrere Versionen
 * mit verschiedenen Gültigkeits-Intervallen haben — an einem Umsteigepunkt stehen Linie 1 und
 * Linie 13 dann womöglich auf verschiedenen Ständen, und „Periode + Fahrplantyp" benennt keinen
 * eindeutigen Fahrplan. Ein *Stand* ist ein Zeitabschnitt, in dem sich an den hier beteiligten
 * Versionen nichts ändert.
 */
final class StopLinkBoardService
{
    public function __construct(
        private readonly ConsolidatedStopNameResolver $stopNames,
        private readonly ConsolidatedTripInfoResolver $tripInfo,
        private readonly StopGroupService $groups,
        private readonly TripLinkValidity $validity,
        private readonly VersionStandService $stands,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function board(StopGroup $gruppe, SchedulePeriod $period, FahrplanTyp $typ, ?int $standIndex = null): array
    {
        $stopIds = $this->groups->stopIdsOf($gruppe);

        $staende = $this->stands->foldFor($this->versionsTouching($stopIds, $period, $typ), $typ);
        $this->attachOpenCounts($staende, $stopIds);
        $stand = $this->stands->pick($staende, $standIndex);

        $aktiveVersionen = $stand === null ? [] : $stand['line_version_ids'];

        $endend = $this->trips($stopIds, $aktiveVersionen, 'last_stop_id');
        $beginnend = $this->trips($stopIds, $aktiveVersionen, 'first_stop_id');

        // Die Entscheidungen **dieses Stands**: Eine Fahrt kann mehrere Anschlüsse tragen, und
        // in einem Stand gilt höchstens einer (KURSE §2 K7).
        $this->attachDecisions($endend, $beginnend, $stand === null ? [] : $stand['ranges']);

        $offen = count(array_filter([...$endend, ...$beginnend], static fn (array $f): bool => $f['decision'] === null));

        Log::debug('Stop link board assembled', [
            'stop_group_id' => $gruppe->id,
            'stop_count' => count($stopIds),
            'period_id' => $period->id,
            'day_type' => $typ->value,
            'stand_count' => count($staende),
            'ending' => count($endend),
            'starting' => count($beginnend),
            'open' => $offen,
        ]);

        $namen = $this->stopNames->namesFor($stopIds);

        return [
            'stop_group' => [
                'id' => $gruppe->id,
                'name' => $gruppe->name,
                // Die einzelnen Bahnsteige bleiben sichtbar: An einer Endstelle liegen
                // Ankunft und Abfahrt oft auf verschiedenen Punkten, und der Pflegende soll
                // sehen, dass die Klammer das zusammenhält — nicht dass es verschwindet.
                'stops' => array_values(array_map(
                    static fn (int $id): array => ['id' => $id, 'name' => $namen[$id] ?? '—'],
                    $stopIds,
                )),
            ],
            'period' => [
                'id' => $period->id,
                'label' => $period->label,
                'status' => $period->status->value,
            ],
            'day_type' => $typ->value,
            'day_type_label' => $typ->label(),
            'stands' => $staende,
            'stand' => $stand,
            'ending' => $endend,
            'starting' => $beginnend,
            'open_count' => $offen,
        ];
    }

    /**
     * Wie viele Versionsstände hat dieser Halt? Getrennt abrufbar, damit das Frontend die
     * Auswahl aufbauen kann, ohne die Fahrten zu laden.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stands(StopGroup $gruppe, SchedulePeriod $period, FahrplanTyp $typ): array
    {
        return $this->stands->foldFor($this->versionsTouching($this->groups->stopIdsOf($gruppe), $period, $typ), $typ);
    }

    /**
     * Die Linien-Versionen der Periode und des Typs, deren Fahrten an diesem Halt beginnen oder
     * enden.
     *
     * @param  array<int, int>  $stopIds
     * @return array<int, int>
     */
    private function versionsTouching(array $stopIds, SchedulePeriod $period, FahrplanTyp $typ): array
    {
        if ($stopIds === []) {
            return [];
        }

        return DB::table('consolidated_trips as ct')
            ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
            ->where('lv.period_id', $period->id)
            ->where('lv.day_type', $typ->value)
            ->where(function ($q) use ($stopIds): void {
                $q->whereIn('ct.first_stop_id', $stopIds)->orWhereIn('ct.last_stop_id', $stopIds);
            })
            ->distinct()
            ->pluck('lv.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Hängt jedem Versionsstand an, wie viele Fahrten dort noch offen sind.
     *
     * **Warum das an den Stand gehört und nicht nur an den gewählten:** Eine Nachtlinie hat im
     * Mo-Fr-Strang regelmäßig Eintagsversionen — die N1 fährt in der Nacht auf einen Feiertag
     * anders, und daraus wird ein Stand, der einen einzigen Tag umfasst. Wer im Hauptstand
     * alles entschieden hat, sieht dort `open_count = 0` und hält die Haltestelle für fertig,
     * während in einem Nebenstand noch eine Fahrt liegt. Die Auswahlliste zählt über die ganze
     * Periode und zeigt die Eins — ohne diese Angabe bliebe der Widerspruch unauflösbar, und
     * man müsste jeden Stand einzeln durchklicken, um die Fahrt zu finden.
     *
     * Je Verkehrsmittel, wie im Haltestellen-Verzeichnis: Die Pflege läuft in Stufen, erst die
     * Straßenbahn. Eine Fahrt, die hier endet **und** beginnt, zählt zweimal — genau wie in
     * `open_count` des Boards, das sie in beiden Spalten führt.
     *
     * @param  array<int, array<string, mixed>>  $staende
     * @param  array<int, int>  $stopIds
     */
    private function attachOpenCounts(array &$staende, array $stopIds): void
    {
        $versionIds = array_values(array_unique(array_merge(
            ...array_map(static fn (array $st): array => $st['line_version_ids'], $staende),
        )));

        if ($versionIds === [] || $stopIds === []) {
            foreach ($staende as &$leer) {
                $leer['open'] = ['total' => 0];
            }
            unset($leer);

            return;
        }

        [$fahrten, $kanten] = $this->tripsAndLinks($stopIds, $versionIds);

        foreach ($staende as &$stand) {
            $aktiv = array_flip($stand['line_version_ids']);
            $zeitraum = $this->validity->forRanges($stand['ranges']);
            $summe = ['total' => 0];

            foreach ($fahrten as $fahrt) {
                if (! isset($aktiv[$fahrt['version_id']])) {
                    continue;
                }

                // Offen heisst: an dieser Seite gilt in diesem Stand keine Entscheidung. Ein
                // Anschluss, der nur in einem anderen Stand traegt, zaehlt hier nicht.
                $entschieden = false;

                foreach ($kanten[$fahrt['seite']][$fahrt['id']] ?? [] as $kante) {
                    if ($this->validity->appliesIn($kante[0], $kante[1], $zeitraum)) {
                        $entschieden = true;
                        break;
                    }
                }

                if ($entschieden) {
                    continue;
                }

                $mittel = $fahrt['mode'];
                $summe[$mittel] = ($summe[$mittel] ?? 0) + 1;
                $summe['total']++;
            }

            $stand['open'] = $summe;
        }
        unset($stand);
    }

    /**
     * Die Fahrten an diesen Halten samt der Zeilen, die an ihnen haengen.
     *
     * Eine Fahrt steht zweimal darin, wenn sie hier endet **und** beginnt (Wendeschleife) —
     * genau wie im Board, das sie in beiden Spalten fuehrt.
     *
     * @param  array<int, int>  $stopIds
     * @param  array<int, int>  $versionIds
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<int, array<int, array{0: int|null, 1: int|null}>>>}
     */
    private function tripsAndLinks(array $stopIds, array $versionIds): array
    {
        $fahrten = [];
        $ids = [];

        foreach (['last_stop_id' => 'from', 'first_stop_id' => 'to'] as $spalte => $seite) {
            $zeilen = DB::table('consolidated_trips')
                ->whereIn('line_version_id', $versionIds)
                ->whereIn($spalte, $stopIds)
                ->get(['id', 'line_version_id', 'route_type']);

            foreach ($zeilen as $zeile) {
                $fahrten[] = [
                    'id' => (int) $zeile->id,
                    'version_id' => (int) $zeile->line_version_id,
                    'mode' => RouteType::modeFor((int) $zeile->route_type),
                    'seite' => $seite,
                ];
                $ids[(int) $zeile->id] = true;
            }
        }

        $kanten = ['from' => [], 'to' => []];

        if ($ids !== []) {
            $zeilen = DB::table('trip_links')
                ->where(function ($q) use ($ids): void {
                    $q->whereIn('from_trip_id', array_keys($ids))->orWhereIn('to_trip_id', array_keys($ids));
                })
                ->get(['from_trip_id', 'to_trip_id']);

            foreach ($zeilen as $zeile) {
                $von = $zeile->from_trip_id === null ? null : (int) $zeile->from_trip_id;
                $nach = $zeile->to_trip_id === null ? null : (int) $zeile->to_trip_id;

                if ($von !== null) {
                    $kanten['from'][$von][] = [$von, $nach];
                }
                if ($nach !== null) {
                    $kanten['to'][$nach][] = [$von, $nach];
                }
            }
        }

        return [$fahrten, $kanten];
    }

    /**
     * Fahrten, die an diesem Halt enden (`last_stop_id`) oder beginnen (`first_stop_id`).
     *
     * @param  array<int, int>  $stopIds
     * @param  array<int, int>  $versionIds
     * @return array<int, array<string, mixed>>
     */
    private function trips(array $stopIds, array $versionIds, string $spalte): array
    {
        if ($versionIds === [] || $stopIds === []) {
            return [];
        }

        $ids = DB::table('consolidated_trips')
            ->whereIn('line_version_id', $versionIds)
            ->whereIn($spalte, $stopIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $liste = array_values(array_map(
            static fn (array $info): array => $info + ['decision' => null],
            $this->tripInfo->forIds($ids),
        ));

        // Endende Fahrten nach Ankunft, beginnende nach Abfahrt — entlang des Betriebstags,
        // nicht der Uhr: Auf der N1 endet die 22:49-Fahrt vor der um 00:19.
        $schluessel = $spalte === 'last_stop_id' ? 'arrival_sort' : 'departure_sort';
        usort($liste, static fn (array $a, array $b): int => $a[$schluessel] <=> $b[$schluessel]);

        return $liste;
    }

    /**
     * Hängt jeder Fahrt ihre Entscheidung an — für eine endende Fahrt, was **danach** kommt,
     * für eine beginnende, was **davor** war.
     *
     * **Nur die Entscheidungen, die in diesem Versionsstand gelten.** Seit eine Fahrt mehrere
     * Anschlüsse tragen darf, ist das eine Auswahl: Wechselt eine Nachbarlinie mitten in der
     * Periode die Version, hat dieselbe Fahrt vor und nach dem Wechseltag verschiedene
     * Nachfolger. Ein Anschluss, der hier an keinem Tag gilt, wird weggelassen — die Fahrt
     * erscheint in diesem Stand also offen, und genau das ist sie auch.
     *
     * @param  array<int, array<string, mixed>>  $endend
     * @param  array<int, array<string, mixed>>  $beginnend
     * @param  array<int, array{valid_from: string, valid_to: string}>  $ranges  Zeiträume des Stands
     */
    private function attachDecisions(array &$endend, array &$beginnend, array $ranges): void
    {
        $endendeIds = array_column($endend, 'id');
        $beginnendeIds = array_column($beginnend, 'id');

        if ($endendeIds === [] && $beginnendeIds === []) {
            return;
        }

        // Der Betriebshof haengt an der Entscheidung, nicht an der Fahrt — er kommt deshalb
        // im selben Zug mit. Ein Join statt einer zweiten Abfrage: Die Zahl der Hoefe ist
        // winzig, die der Entscheidungen nicht.
        $links = DB::table('trip_links as tl')
            ->leftJoin('depots as d', 'd.id', '=', 'tl.depot_id')
            ->where(function ($q) use ($endendeIds, $beginnendeIds): void {
                if ($endendeIds !== []) {
                    $q->whereIn('tl.from_trip_id', $endendeIds);
                }
                if ($beginnendeIds !== []) {
                    $q->orWhereIn('tl.to_trip_id', $beginnendeIds);
                }
            })
            ->select(
                'tl.id', 'tl.from_trip_id', 'tl.to_trip_id', 'tl.kind', 'tl.note', 'tl.depot_id',
                'd.name as depot_name', 'd.short_name as depot_short_name', 'd.active as depot_active',
            )
            ->get();

        if ($links->isEmpty()) {
            return;
        }

        // Partner können in einer Version liegen, die im gewählten Stand gar nicht aktiv ist
        // — etwa wenn die Gegenlinie inzwischen eine neue Version hat. Sie werden deshalb
        // eigenständig nachgeladen, nicht aus den beiden Listen genommen.
        $partnerIds = $links
            ->flatMap(static fn (object $l): array => [$l->from_trip_id, $l->to_trip_id])
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $partner = $this->tripInfo->forIds($partnerIds);

        // Der Zeitraum dieses Stands. Ein Anschluss gilt hier, wenn er ihn an mindestens einem
        // Tag berührt — gerechnet in Betriebstagen, wie die Intervalle selbst.
        $zeitraum = $this->validity->forRanges($ranges);

        $gilt = fn (object $link): bool => $this->validity->appliesIn(
            $link->from_trip_id === null ? null : (int) $link->from_trip_id,
            $link->to_trip_id === null ? null : (int) $link->to_trip_id,
            $zeitraum,
        );

        $links = $links->filter($gilt);

        if ($links->isEmpty()) {
            return;
        }

        $nachFrom = $links->whereNotNull('from_trip_id')->keyBy('from_trip_id');
        $nachTo = $links->whereNotNull('to_trip_id')->keyBy('to_trip_id');

        foreach ($endend as &$fahrt) {
            $link = $nachFrom->get($fahrt['id']);

            if ($link !== null) {
                $fahrt['decision'] = $this->describeDecision($link, $partner, gegenueber: 'to_trip_id');
            }
        }
        unset($fahrt);

        foreach ($beginnend as &$fahrt) {
            $link = $nachTo->get($fahrt['id']);

            if ($link !== null) {
                $fahrt['decision'] = $this->describeDecision($link, $partner, gegenueber: 'from_trip_id');
            }
        }
        unset($fahrt);
    }

    /**
     * @param  array<int, array<string, mixed>>  $partner
     * @return array<string, mixed>
     */
    private function describeDecision(object $link, array $partner, string $gegenueber): array
    {
        $kind = TripLinkKind::from($link->kind);
        $partnerId = $link->{$gegenueber} === null ? null : (int) $link->{$gegenueber};

        $wendezeit = null;

        if ($kind === TripLinkKind::Link && $link->from_trip_id !== null && $link->to_trip_id !== null) {
            // Entlang des Betriebstags, nicht der Uhr: Auf der N1 folgt auf eine Ankunft um
            // 23:20 eine Abfahrt um 00:19 — 59 Minuten Wende, kein Ruecksprung.
            $ankunft = $partner[(int) $link->from_trip_id]['arrival_sort'] ?? PHP_INT_MAX;
            $abfahrt = $partner[(int) $link->to_trip_id]['departure_sort'] ?? PHP_INT_MAX;

            $wendezeit = ($ankunft === PHP_INT_MAX || $abfahrt === PHP_INT_MAX) ? null : $abfahrt - $ankunft;
        }

        return [
            'id' => (int) $link->id,
            'kind' => $kind->value,
            'partner' => $partnerId === null ? null : ($partner[$partnerId] ?? null),
            'turnaround_seconds' => $wendezeit,
            'depot' => $this->describeDepot($link),
            'note' => $link->note,
        ];
    }

    /**
     * Der Betriebshof an dieser Entscheidung — `null` heisst **noch offen**, nicht „kein Hof".
     *
     * Beginnt eine Kette an einer Endstelle, ist der Hof oft nicht bekannt; die Angabe ist
     * deshalb freiwillig, und ihr Fehlen ist kein Pflegerueckstand.
     *
     * @return array<string, mixed>|null
     */
    private function describeDepot(object $link): ?array
    {
        if ($link->depot_id === null) {
            return null;
        }

        $kurz = $link->depot_short_name;

        return [
            'id' => (int) $link->depot_id,
            'name' => (string) $link->depot_name,
            'display' => $kurz === null || $kurz === '' ? (string) $link->depot_name : (string) $kurz,
            'active' => (bool) $link->depot_active,
        ];
    }
}
