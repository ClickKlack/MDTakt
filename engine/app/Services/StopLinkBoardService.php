<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Enums\TripLinkKind;
use App\Models\SchedulePeriod;
use App\Models\StopGroup;
use Carbon\CarbonImmutable;
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
        private readonly FahrplanTypClassifier $classifier,
    ) {}

    /** @var array<string, FahrplanTyp> Datum => Typ, damit der Klassifizierer nicht je Tag erneut abfragt */
    private array $typCache = [];

    /**
     * @return array<string, mixed>
     */
    public function board(StopGroup $gruppe, SchedulePeriod $period, FahrplanTyp $typ, ?int $standIndex = null): array
    {
        $stopIds = $this->groups->stopIdsOf($gruppe);

        $versionen = $this->versionsTouching($stopIds, $period, $typ);
        $staende = $this->foldStands($versionen, $typ);
        $stand = $this->pickStand($staende, $standIndex);

        $aktiveVersionen = $stand === null ? [] : $stand['line_version_ids'];

        $endend = $this->trips($stopIds, $aktiveVersionen, 'last_stop_id');
        $beginnend = $this->trips($stopIds, $aktiveVersionen, 'first_stop_id');

        $this->attachDecisions($endend, $beginnend);

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
        return $this->foldStands(
            $this->versionsTouching($this->groups->stopIdsOf($gruppe), $period, $typ),
            $typ,
        );
    }

    /**
     * Linien-Versionen der Periode und des Typs, deren Fahrten an diesem Halt beginnen oder
     * enden — samt ihrer beobachteten Gültigkeits-Intervalle.
     *
     * @param  array<int, int>  $stopIds
     * @return array<int, array{id: int, intervals: array<int, array{from: CarbonImmutable, to: CarbonImmutable}>}>
     */
    private function versionsTouching(array $stopIds, SchedulePeriod $period, FahrplanTyp $typ): array
    {
        if ($stopIds === []) {
            return [];
        }

        $versionIds = DB::table('consolidated_trips as ct')
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

        if ($versionIds === []) {
            return [];
        }

        $intervalle = DB::table('line_version_intervals')
            ->whereIn('line_version_id', $versionIds)
            ->orderBy('valid_from')
            ->get()
            ->groupBy('line_version_id');

        $ergebnis = [];

        foreach ($versionIds as $id) {
            $ergebnis[] = [
                'id' => $id,
                'intervals' => $intervalle->get($id, collect())
                    ->map(static fn (object $i): array => [
                        'from' => CarbonImmutable::parse($i->valid_from)->startOfDay(),
                        'to' => CarbonImmutable::parse($i->valid_to)->startOfDay(),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $ergebnis;
    }

    /**
     * Zerlegt den Zeitstrahl an allen Intervallgrenzen und verschmilzt wieder, wo dieselbe
     * Versionsmenge gilt. Übrig bleiben genau die Abschnitte, in denen sich am beteiligten
     * Fahrplan nichts ändert.
     *
     * @param  array<int, array{id: int, intervals: array<int, array{from: CarbonImmutable, to: CarbonImmutable}>}>  $versionen
     * @return array<int, array<string, mixed>>
     */
    private function foldStands(array $versionen, FahrplanTyp $typ): array
    {
        $grenzen = [];

        foreach ($versionen as $version) {
            foreach ($version['intervals'] as $intervall) {
                // Der Tag nach dem Ende ist ebenfalls eine Grenze — sonst verschwände der
                // Übergang von „gilt" zu „gilt nicht mehr".
                $grenzen[$intervall['from']->toDateString()] = $intervall['from'];
                $grenzen[$intervall['to']->addDay()->toDateString()] = $intervall['to']->addDay();
            }
        }

        if ($grenzen === []) {
            return [];
        }

        ksort($grenzen);
        $punkte = array_values($grenzen);

        $roh = [];

        for ($i = 0; $i < count($punkte) - 1; $i++) {
            $von = $punkte[$i];
            $bis = $punkte[$i + 1]->subDay();

            $aktiv = [];

            foreach ($versionen as $version) {
                foreach ($version['intervals'] as $intervall) {
                    if ($intervall['from'] <= $von && $von <= $intervall['to']) {
                        $aktiv[] = $version['id'];
                        break;
                    }
                }
            }

            if ($aktiv === []) {
                continue;
            }

            // Auf die Tage des gewählten Typs beschneiden. Ein Abschnitt, in den keiner
            // fällt, ist kein Fahrplanstand, sondern die Lücke dazwischen — im `mo_fr`-Strang
            // sind das die Wochenenden. Und ein Zeitraum, der am Samstag beginnt, obwohl nur
            // Mo-Fr zählt, nennt ein Datum, an dem nichts gilt.
            $beschnitten = $this->trimToType($von, $bis, $typ);

            if ($beschnitten === null) {
                continue;
            }

            sort($aktiv);
            $roh[] = ['from' => $beschnitten[0], 'to' => $beschnitten[1], 'versions' => $aktiv];
        }

        return $this->groupByVersionSet($roh);
    }

    /**
     * Schneidet einen Abschnitt auf den ersten und letzten Tag des gewählten Fahrplantyps zu.
     * `null`, wenn gar keiner darin liegt.
     *
     * Bereits geprüfte Tage werden gemerkt — der Klassifizierer fragt je Datum die
     * Ferien-Tabelle ab, und dieselben Tage kommen über mehrere Abschnitte hinweg wieder vor.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function trimToType(CarbonImmutable $von, CarbonImmutable $bis, FahrplanTyp $typ): ?array
    {
        $erster = null;
        $letzter = null;

        for ($tag = $von; $tag <= $bis; $tag = $tag->addDay()) {
            $schluessel = $tag->toDateString();

            $this->typCache[$schluessel] ??= $this->classifier->classify($tag);

            if ($this->typCache[$schluessel] !== $typ) {
                continue;
            }

            $erster ??= $tag;
            $letzter = $tag;
        }

        return $erster === null ? null : [$erster, $letzter];
    }

    /**
     * Fasst alle Abschnitte mit **derselben Versionsmenge** zu einem Stand zusammen — auch
     * wenn sie nicht aneinandergrenzen.
     *
     * Das ist dieselbe Denkweise wie bei den Linien-Versionen selbst (FAHRPLANPERIODEN §5.4 a):
     * Ein Stand ist über seinen *Inhalt* identifiziert, nicht über seine Laufzeit, und trägt
     * mehrere Gültigkeits-Zeiträume.
     *
     * Ohne das zerfällt die Auswahl: Nachtlinien wechseln im `mo_fr`-Strang wöchentlich die
     * Version, weil die Nacht von Sonntag auf Montag eine Sonntagsnacht ist (FAHRPLANPERIODEN
     * §8). An Herrenkrug ergab das 16 Stände über vier Wochen — einen je Montag und je
     * Di–Fr-Block —, obwohl es dort nur drei verschiedene Fahrplanstände gibt.
     *
     * @param  array<int, array{from: CarbonImmutable, to: CarbonImmutable, versions: array<int, int>}>  $roh
     * @return array<int, array<string, mixed>>
     */
    private function groupByVersionSet(array $roh): array
    {
        $staende = [];

        foreach ($roh as $abschnitt) {
            $schluessel = implode(',', $abschnitt['versions']);

            if (! isset($staende[$schluessel])) {
                $staende[$schluessel] = ['versions' => $abschnitt['versions'], 'ranges' => []];
            }

            $ranges = &$staende[$schluessel]['ranges'];
            $letzter = $ranges === [] ? null : $ranges[count($ranges) - 1];

            // Angrenzende Zeiträume desselben Standes bleiben ein Zeitraum.
            if ($letzter !== null && $letzter['to']->addDay()->equalTo($abschnitt['from'])) {
                $ranges[count($ranges) - 1]['to'] = $abschnitt['to'];
            } else {
                $ranges[] = ['from' => $abschnitt['from'], 'to' => $abschnitt['to']];
            }

            unset($ranges);
        }

        // Nach dem ersten Zeitraum sortieren, damit die Auswahl chronologisch bleibt.
        uasort($staende, static fn (array $a, array $b): int => $a['ranges'][0]['from'] <=> $b['ranges'][0]['from']);

        $ergebnis = [];
        $index = 0;

        foreach ($staende as $stand) {
            $von = $stand['ranges'][0]['from'];
            $bis = $stand['ranges'][count($stand['ranges']) - 1]['to'];

            $ergebnis[] = [
                'index' => $index++,
                // Gesamtspanne für die kurze Beschriftung …
                'valid_from' => $von->toDateString(),
                'valid_to' => $bis->toDateString(),
                // … die einzelnen Zeiträume für die ehrliche Antwort, wann der Stand gilt.
                'ranges' => array_map(
                    static fn (array $r): array => [
                        'valid_from' => $r['from']->toDateString(),
                        'valid_to' => $r['to']->toDateString(),
                    ],
                    $stand['ranges'],
                ),
                'line_version_ids' => $stand['versions'],
                'line_count' => count($stand['versions']),
            ];
        }

        return $ergebnis;
    }

    /**
     * Ohne Vorgabe der Abschnitt, der heute enthält — sonst der letzte. Der Pflegende soll
     * beim Öffnen den Stand sehen, an dem er gerade arbeitet.
     *
     * @param  array<int, array<string, mixed>>  $staende
     * @return array<string, mixed>|null
     */
    private function pickStand(array $staende, ?int $standIndex): ?array
    {
        if ($staende === []) {
            return null;
        }

        if ($standIndex !== null) {
            foreach ($staende as $stand) {
                if ($stand['index'] === $standIndex) {
                    return $stand;
                }
            }

            return null;
        }

        $heute = CarbonImmutable::now()->toDateString();

        foreach ($staende as $stand) {
            if ($stand['valid_from'] <= $heute && $heute <= $stand['valid_to']) {
                return $stand;
            }
        }

        return $staende[count($staende) - 1];
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
     * @param  array<int, array<string, mixed>>  $endend
     * @param  array<int, array<string, mixed>>  $beginnend
     */
    private function attachDecisions(array &$endend, array &$beginnend): void
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
