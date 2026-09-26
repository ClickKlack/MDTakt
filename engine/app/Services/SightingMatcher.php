<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SightingMatch;
use App\Models\MdktRoute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ordnet eine Sichtung aus MDKursTracker einer Fahrt des Konsolidats zu.
 *
 * Verfahren (INTEGRATION_MDKURSTRACKER §4.1, validiert): **Linie + Fahrplantyp + Uhrzeitfolge**,
 * ohne Haltestellen-Crosswalk. Die Engine bildet dafür aus dem Laufweg des Trackers exakt die
 * Signatur nach, die der Import je Fahrt schreibt ({@see TripSignatureService::signatureFor()}),
 * und sucht sie per Index. Es gibt **keine unscharfe Zeitsuche** — was nicht minutengenau passt,
 * bleibt ohne Treffer und wird nach dem nächsten Import erneut versucht.
 *
 * Drei Eigenheiten des Feeds bestimmen, wie die Uhrzeitfolge aussehen muss:
 *
 * 1. **Linienwechsel.** Eine Tracker-Fahrt L5 → L1 besteht im Feed aus zwei Fahrten. Der Laufweg
 *    wird deshalb an jedem Wechsel der Linie je Halt geteilt. Zu welchem Teil der Übergangshalt
 *    gehört, ist aus HAFAS nicht sicher abzulesen — er wird darum in beiden Varianten versucht.
 * 2. **Mitternacht.** Eine Fahrt, die vor Mitternacht beginnt, läuft im Feed als `24:08` weiter;
 *    eine, die danach beginnt, steht mit `00:19` da. Gezählt wird also ab dem Kalendertag des
 *    ersten Halts der Fahrt.
 * 3. **Betriebstag.** Eine Fahrt vor der Betriebstag-Grenze gehört zum Vortag und trägt dessen
 *    Fahrplantyp ({@see OperatingDayResolver}). Der Tag wird aus der Sichtung abgeleitet, nicht aus
 *    dem Laufweg: Dessen Zeiten stammen vom Tag, an dem der Tracker ihn erfasst hat.
 *
 * Alle lokalen Zeiten sind **Europe/Berlin** — die Netz-Zeit, in der GTFS seine Uhrzeiten führt.
 */
final class SightingMatcher
{
    /**
     * Wie weit eine Folgeversion nach dem Tag der Sichtung beginnen darf, um noch als Treffer zu
     * gelten (entschieden 26.09.2026). Der Feed kommt wöchentlich; zwei Wochen decken auch einen
     * ausgefallenen Lauf ab, ohne eine Sichtung an einen Fahrplan zu hängen, der mit ihr nichts
     * mehr zu tun hat.
     */
    public const NEXT_VERSION_DAYS = 14;

    private const NETWORK_TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private readonly FahrplanTypClassifier $classifier,
        private readonly OperatingDayResolver $operatingDay,
    ) {}

    /**
     * @param  string  $line  Linie am gesichteten Halt
     * @param  CarbonImmutable  $departurePlanned  Soll-Abfahrt der Sichtung (beliebige Zone)
     */
    public function match(MdktRoute $route, string $line, string $hafasStopId, CarbonImmutable $departurePlanned): SightingMatchResult
    {
        $halte = $this->localStops($route->stops);

        if ($halte === []) {
            return SightingMatchResult::none('route-without-times');
        }

        $gesichtet = $departurePlanned->setTimezone(self::NETWORK_TIMEZONE);
        $position = $this->sightingPosition($halte, $line, $hafasStopId, $gesichtet);

        if ($position === null) {
            return SightingMatchResult::none('stop-not-on-route');
        }

        $segmente = $this->segments($halte);
        $index = $this->segmentFor($segmente, $position, $line);
        $segment = $segmente[$index];

        // Tage zwischen dem Laufweg-Tag des Trackers und dem Tag der Sichtung — um so viel liegt
        // der Start der gesichteten Fahrt neben dem Start im gespeicherten Laufweg.
        $anker = $halte[$position]['time'];
        $versatz = self::daysBetween($anker, $gesichtet);

        $varianten = $this->variants($halte, $segmente, $index);
        $jeTag = [];

        foreach ($varianten as $zeiten) {
            $start = $zeiten[0];
            $betriebstag = CarbonImmutable::parse($start->toDateString(), 'UTC')->addDays($versatz);

            if ($this->operatingDay->shiftsBack($segment['line'], (int) $start->secondsSinceMidnight())) {
                $betriebstag = $betriebstag->subDay();
            }

            $typ = $this->classifier->classify($betriebstag)->value;
            $signatur = TripSignatureService::signatureFor($segment['line'], $typ, self::sequence($zeiten));

            $jeTag[$betriebstag->toDateString()][$typ][$signatur] = true;
        }

        return $this->lookup($segment['line'], $jeTag);
    }

    /**
     * Sucht die Signaturen erst in der gültigen Version, dann in der nächsten.
     *
     * @param  array<string, array<string, array<string, true>>>  $jeTag  Betriebstag → Typ → Signaturen
     */
    private function lookup(string $line, array $jeTag): SightingMatchResult
    {
        $treffer = [];
        $spaeter = [];

        foreach ($jeTag as $datum => $jeTyp) {
            foreach ($jeTyp as $typ => $signaturen) {
                foreach ($this->validTrips($line, $typ, $datum, array_keys($signaturen)) as $zeile) {
                    $treffer[(int) $zeile->id] = [$zeile->signature, $datum];
                }
            }
        }

        $datum = array_key_first($jeTag);

        if (count($treffer) === 1) {
            $id = array_key_first($treffer);

            return new SightingMatchResult(SightingMatch::Matched, $id, $treffer[$id][0], $treffer[$id][1]);
        }

        if (count($treffer) > 1) {
            return $this->ambiguous($line, $datum, array_keys($treffer));
        }

        // Kein Treffer am Tag selbst: Vielleicht kennt erst die nächste Version den Laufweg.
        foreach ($jeTag as $tag => $jeTyp) {
            foreach ($jeTyp as $typ => $signaturen) {
                foreach ($this->laterTrips($line, $typ, $tag, array_keys($signaturen)) as $zeile) {
                    $spaeter[] = ['id' => (int) $zeile->id, 'signature' => $zeile->signature, 'from' => substr((string) $zeile->valid_from, 0, 10), 'date' => $tag];
                }
            }
        }

        if ($spaeter === []) {
            return SightingMatchResult::none('no-trip-match', $datum);
        }

        // Nur die früheste Folgeversion zählt — eine noch spätere wäre ein anderer Fahrplan.
        $fruehester = min(array_column($spaeter, 'from'));
        $naechste = array_values(array_filter($spaeter, static fn (array $z): bool => $z['from'] === $fruehester));
        $ids = array_values(array_unique(array_column($naechste, 'id')));

        if (count($ids) > 1) {
            return $this->ambiguous($line, $datum, $ids);
        }

        return new SightingMatchResult(SightingMatch::MatchedNextVersion, $ids[0], $naechste[0]['signature'], $naechste[0]['date']);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function ambiguous(string $line, ?string $datum, array $ids): SightingMatchResult
    {
        Log::warning('Sighting signature matches several trips', [
            'line' => $line,
            'operating_date' => $datum,
            'trip_ids' => $ids,
        ]);

        return new SightingMatchResult(SightingMatch::Ambiguous, operatingDate: $datum, reason: 'several-trips');
    }

    /**
     * Fahrten mit einer der Signaturen in der Version, deren beobachtetes Intervall den Tag
     * einschließt — dieselbe Gültigkeitsregel wie {@see ConsolidatedScheduleService::trips()}.
     *
     * @param  array<int, string>  $signaturen
     * @return iterable<int, object>
     */
    private function validTrips(string $line, string $typ, string $datum, array $signaturen): iterable
    {
        return DB::table('consolidated_trips as ct')
            ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
            ->where('lv.line', $line)
            ->where('lv.day_type', $typ)
            ->whereIn('ct.signature', $signaturen)
            ->whereExists(function ($sub) use ($datum): void {
                $sub->select(DB::raw(1))
                    ->from('line_version_intervals as i')
                    ->whereColumn('i.line_version_id', 'lv.id')
                    ->whereDate('i.valid_from', '<=', $datum)
                    ->whereDate('i.valid_to', '>=', $datum);
            })
            ->select('ct.id', 'ct.signature')
            ->distinct()
            ->get();
    }

    /**
     * Fahrten mit einer der Signaturen in einer Version, die erst **nach** dem Tag beginnt,
     * höchstens {@see NEXT_VERSION_DAYS} Tage später.
     *
     * @param  array<int, string>  $signaturen
     * @return iterable<int, object>
     */
    private function laterTrips(string $line, string $typ, string $datum, array $signaturen): iterable
    {
        $bis = CarbonImmutable::parse($datum)->addDays(self::NEXT_VERSION_DAYS)->toDateString();

        return DB::table('consolidated_trips as ct')
            ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
            ->join('line_version_intervals as i', 'i.line_version_id', '=', 'lv.id')
            ->where('lv.line', $line)
            ->where('lv.day_type', $typ)
            ->whereIn('ct.signature', $signaturen)
            ->whereDate('i.valid_from', '>', $datum)
            ->whereDate('i.valid_from', '<=', $bis)
            ->select('ct.id', 'ct.signature', 'i.valid_from')
            ->get();
    }

    /**
     * Laufweg nach `seq`, Zeiten in Netz-Zeit. Halte ganz ohne Zeit fallen heraus — der Import
     * überspringt sie in der Signatur genauso.
     *
     * @param  array<int, array<string, mixed>>  $stops
     * @return array<int, array{hafas: string, line: string, arr: ?CarbonImmutable, dep: ?CarbonImmutable, time: CarbonImmutable}>
     */
    private function localStops(array $stops): array
    {
        usort($stops, static fn (array $a, array $b): int => (int) $a['seq'] <=> (int) $b['seq']);

        $halte = [];

        foreach ($stops as $stop) {
            $ankunft = self::local($stop['arrival_planned'] ?? null);
            $abfahrt = self::local($stop['departure_planned'] ?? null);

            if ($ankunft === null && $abfahrt === null) {
                continue;
            }

            $halte[] = [
                'hafas' => (string) $stop['hafas_stop_id'],
                'line' => (string) $stop['line'],
                'arr' => $ankunft,
                'dep' => $abfahrt,
                'time' => $abfahrt ?? $ankunft,
            ];
        }

        return $halte;
    }

    /**
     * Wo auf dem Laufweg wurde gesichtet? Ein Halt kann auf Ringfahrten zweimal vorkommen — dann
     * entscheidet die Uhrzeit.
     *
     * @param  array<int, array{hafas: string, line: string, time: CarbonImmutable}>  $halte
     */
    private function sightingPosition(array $halte, string $line, string $hafasStopId, CarbonImmutable $gesichtet): ?int
    {
        $uhrzeit = $gesichtet->format('H:i');
        $kandidaten = array_keys(array_filter($halte, static fn (array $h): bool => $h['hafas'] === $hafasStopId));

        if ($kandidaten === []) {
            // Rückfall: Halt-ID unbekannt, aber Linie und Uhrzeit passen.
            $kandidaten = array_keys(array_filter(
                $halte,
                static fn (array $h): bool => $h['line'] === $line && $h['time']->format('H:i') === $uhrzeit,
            ));
        }

        if ($kandidaten === []) {
            return null;
        }

        foreach ($kandidaten as $i) {
            if ($halte[$i]['time']->format('H:i') === $uhrzeit) {
                return $i;
            }
        }

        return $kandidaten[0];
    }

    /**
     * Zusammenhängende Abschnitte gleicher Linie.
     *
     * @param  array<int, array{line: string}>  $halte
     * @return array<int, array{line: string, from: int, to: int}>
     */
    private function segments(array $halte): array
    {
        $segmente = [];

        foreach ($halte as $i => $halt) {
            $letztes = count($segmente) - 1;

            if ($letztes >= 0 && $segmente[$letztes]['line'] === $halt['line']) {
                $segmente[$letztes]['to'] = $i;

                continue;
            }

            $segmente[] = ['line' => $halt['line'], 'from' => $i, 'to' => $i];
        }

        return $segmente;
    }

    /**
     * Der Abschnitt der Sichtung. Liegt sie genau auf dem Übergangshalt, trägt der Halt im
     * Laufweg womöglich die andere Linie — dann gilt der Nachbarabschnitt mit der gesichteten.
     *
     * @param  array<int, array{line: string, from: int, to: int}>  $segmente
     */
    private function segmentFor(array $segmente, int $position, string $line): int
    {
        foreach ($segmente as $i => $segment) {
            if ($position >= $segment['from'] && $position <= $segment['to']) {
                if ($segment['line'] === $line) {
                    return $i;
                }

                if (isset($segmente[$i + 1]) && $segmente[$i + 1]['line'] === $line && $position === $segment['to']) {
                    return $i + 1;
                }

                if (isset($segmente[$i - 1]) && $segmente[$i - 1]['line'] === $line && $position === $segment['from']) {
                    return $i - 1;
                }

                return $i;
            }
        }

        return 0;
    }

    /**
     * Die Uhrzeitfolgen, unter denen der Abschnitt im Feed stehen kann: mit oder ohne den
     * Übergangshalt an jedem Ende, und am letzten Halt Ankunft oder Abfahrt.
     *
     * @param  array<int, array{arr: ?CarbonImmutable, dep: ?CarbonImmutable}>  $halte
     * @param  array<int, array{line: string, from: int, to: int}>  $segmente
     * @return array<int, array<int, CarbonImmutable>>
     */
    private function variants(array $halte, array $segmente, int $index): array
    {
        $segment = $segmente[$index];
        $anfaenge = [$segment['from']];
        $enden = [$segment['to']];

        if ($index > 0) {
            $anfaenge[] = $segment['from'] - 1;
        }

        if ($index < count($segmente) - 1) {
            $enden[] = $segment['to'] + 1;
        }

        $varianten = [];

        foreach ($anfaenge as $von) {
            foreach ($enden as $bis) {
                if ($bis <= $von) {
                    continue;
                }

                $mitte = [];

                for ($i = $von; $i < $bis; $i++) {
                    $mitte[] = $halte[$i]['dep'] ?? $halte[$i]['arr'];
                }

                // Am Endhalt steht im Feed meist die Ankunft; HAFAS liefert dort je nach Halt
                // nur die eine oder die andere. Beide Lesarten werden versucht.
                $letzte = [];

                foreach ([$halte[$bis]['arr'], $halte[$bis]['dep']] as $t) {
                    if ($t !== null) {
                        $letzte[$t->format('Y-m-d H:i')] = $t;
                    }
                }

                foreach ($letzte as $ende) {
                    $varianten[] = [...$mitte, $ende];
                }
            }
        }

        return $varianten;
    }

    /**
     * „HH:MM,HH:MM,…" wie im Feed: ab dem Kalendertag des ersten Halts gezählt, danach über 24.
     *
     * @param  array<int, CarbonImmutable>  $zeiten
     */
    private static function sequence(array $zeiten): string
    {
        $start = $zeiten[0];

        return implode(',', array_map(static function (CarbonImmutable $t) use ($start): string {
            $stunde = $t->hour + 24 * self::daysBetween($start, $t);

            return sprintf('%02d:%02d', $stunde, $t->minute);
        }, $zeiten));
    }

    /**
     * Kalendertage zwischen zwei lokalen Zeitpunkten. Über das Datum gerechnet, nicht über
     * Sekunden: An den Umstellungstagen hat ein Tag 23 oder 25 Stunden.
     */
    private static function daysBetween(CarbonImmutable $von, CarbonImmutable $bis): int
    {
        $a = CarbonImmutable::parse($von->toDateString(), 'UTC');
        $b = CarbonImmutable::parse($bis->toDateString(), 'UTC');

        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    private static function local(?string $utc): ?CarbonImmutable
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        return CarbonImmutable::parse($utc)->setTimezone(self::NETWORK_TIMEZONE);
    }
}
