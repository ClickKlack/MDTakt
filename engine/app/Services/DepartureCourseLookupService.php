<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SightingStatus;
use App\Support\GtfsTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Kursauskunft für MDKursTracker (Fluss 2, INTEGRATION_MDKURSTRACKER §5.2): „Welcher Kurs fährt
 * diese Abfahrt?" — beschrieben durch Linie, HAFAS-Halt und Soll-Zeit, so wie sie auf einer
 * Abfahrtstafel steht (entschieden 26.09.2026). Einen Laufweg hat der Tracker dort nicht.
 *
 * Gesucht wird die Fahrt der Linie, die am Betriebstag genau zu dieser Minute an einem Halt abfährt.
 * Fahren zur selben Minute mehrere (Gegenrichtung, zweiter Bahnsteig), grenzt der Halt ein:
 *
 * 1. **Der HAFAS-Halt aus den Sichtungen.** Jede zugeordnete Sichtung verrät, welcher Konsolidat-Halt
 *    zu ihrer HAFAS-ID gehört — der Halt ihrer Fahrt zu ihrer Uhrzeit. Die Zuordnung wächst mit jeder
 *    Sichtung, ohne eigene Tabelle.
 * 2. **Der Haltname**, normalisiert wie bei der Konsolidierung ({@see StopNameNormalizer}).
 *
 * Es wird nichts geraten: Bleiben zwei Fahrten übrig, lautet die Antwort `ambiguous`.
 */
final class DepartureCourseLookupService
{
    private const NETWORK_TIMEZONE = 'Europe/Berlin';

    /** Die gelernte Halt-Zuordnung ändert sich nur mit neuen Sichtungen — eine Stunde genügt. */
    private const STOP_MAP_TTL_SECONDS = 3600;

    private const STOP_MAP_CACHE_KEY = 'course-lookup.hafas-stop-map';

    public function __construct(
        private readonly FahrplanTypClassifier $classifier,
        private readonly OperatingDayResolver $operatingDay,
        private readonly ConsolidatedTripTimeResolver $tripTimes,
        private readonly ConsolidatedStopNameResolver $stopNames,
        private readonly StopNameNormalizer $normalizer,
        private readonly CourseLookup $courses,
    ) {}

    /**
     * @param  array{hafas_stop: string, line: string, time: string, stop_name?: ?string, direction?: ?string}  $abfahrt
     * @return array<string, mixed>
     */
    public function lookup(array $abfahrt): array
    {
        $line = (string) $abfahrt['line'];
        $lokal = CarbonImmutable::parse((string) $abfahrt['time'])->setTimezone(self::NETWORK_TIMEZONE);
        $kandidaten = $this->candidates($line, $lokal);

        if ($kandidaten === []) {
            return $this->notFound('no-trip-match');
        }

        [$kandidaten, $weg] = $this->narrowByStop($kandidaten, (string) $abfahrt['hafas_stop'], $abfahrt['stop_name'] ?? null);
        $fahrten = array_values(array_unique(array_column($kandidaten, 'trip_id')));

        // Fahren beide Richtungen zur selben Minute an gleichnamigen Bahnsteigen ab, trennt das Ziel.
        if (count($fahrten) > 1 && ! empty($abfahrt['direction'])) {
            $nachZiel = $this->narrowByDirection($kandidaten, (string) $abfahrt['direction']);

            if ($nachZiel !== []) {
                $kandidaten = $nachZiel;
                $fahrten = array_values(array_unique(array_column($kandidaten, 'trip_id')));
                $weg .= '+direction';
            }
        }

        if ($fahrten === []) {
            return $this->notFound('no-trip-match');
        }

        if (count($fahrten) > 1) {
            Log::debug('Course lookup ambiguous', ['line' => $line, 'time' => $abfahrt['time'], 'trip_ids' => $fahrten, 'resolved_via' => $weg]);

            return $this->notFound('ambiguous');
        }

        $treffer = $kandidaten[0];
        $kurs = $this->courses->forTrips([$treffer['trip_id']])[$treffer['trip_id']] ?? null;

        if ($kurs === null) {
            return $this->notFound('no-course-assigned');
        }

        // Kein Konfidenz-Wert: MD-Takt ist die Wahrheit. Ein durch Fortschreiben gesetzter Kurs gilt
        // genauso wie einer, den eine Sichtung belegt (entschieden 26.09.2026).
        return [
            'found' => true,
            'course_number' => $kurs['number'],
            'display' => $line.'/'.$kurs['number'],
            'line' => $line,
            'matched_trip' => [
                'id' => $treffer['trip_id'],
                'line_version_id' => $treffer['line_version_id'],
                'departure_local' => $treffer['time'],
            ],
            'stop_resolved_via' => $weg,
        ];
    }

    /**
     * Fahrten der Linie mit einer Abfahrt in genau dieser Minute — in allen drei Schreibweisen,
     * unter denen der Feed eine Zeit um Mitternacht führt:
     *
     * - `HH:MM` am Kalendertag: die Fahrt beginnt nach der Betriebstag-Grenze (Normalfall)
     * - `HH:MM` am Vortag: die Fahrt beginnt nach Mitternacht, aber vor der Grenze — sie gehört zum Vortag
     * - `HH+24:MM` am Vortag: die Fahrt begann vor Mitternacht und läuft darüber hinaus
     *
     * @return array<int, array{trip_id: int, stop_id: int, line_version_id: int, time: string}>
     */
    private function candidates(string $line, CarbonImmutable $lokal): array
    {
        $tag = CarbonImmutable::parse($lokal->toDateString(), 'UTC');
        $vortag = $tag->subDay();
        $uhr = $lokal->format('H:i');
        $spaet = sprintf('%02d:%s', $lokal->hour + 24, $lokal->format('i'));

        $varianten = [
            ['tag' => $tag, 'zeit' => $uhr, 'verschoben' => false],
            ['tag' => $vortag, 'zeit' => $uhr, 'verschoben' => true],
            ['tag' => $vortag, 'zeit' => $spaet, 'verschoben' => null],
        ];

        $ergebnis = [];

        foreach ($varianten as $v) {
            $zeilen = $this->tripsDepartingAt($line, $v['tag'], $v['zeit']);

            if ($zeilen === []) {
                continue;
            }

            // Ob die Fahrt vor der Grenze beginnt, entscheidet, zu welchem Tag sie gehört.
            // Bei „24:05" ist die Frage beantwortet: Wer so notiert ist, begann am Vortag.
            $starts = $v['verschoben'] === null ? [] : $this->tripTimes->endpoints(array_column($zeilen, 'trip_id'));

            foreach ($zeilen as $z) {
                if ($v['verschoben'] !== null) {
                    $start = GtfsTime::toSeconds($starts[$z['trip_id']]['departure'] ?? null);

                    if ($this->operatingDay->shiftsBack($line, $start) !== $v['verschoben']) {
                        continue;
                    }
                }

                $ergebnis[] = $z;
            }
        }

        return $ergebnis;
    }

    /**
     * @return array<int, array{trip_id: int, stop_id: int, line_version_id: int, time: string}>
     */
    private function tripsDepartingAt(string $line, CarbonImmutable $betriebstag, string $hhmm): array
    {
        $datum = $betriebstag->toDateString();
        $typ = $this->classifier->classify($betriebstag)->value;

        return DB::table('consolidated_stop_times as cst')
            ->join('consolidated_trips as ct', 'ct.id', '=', 'cst.consolidated_trip_id')
            ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
            ->where('lv.line', $line)
            ->where('lv.day_type', $typ)
            ->whereExists(function ($sub) use ($datum): void {
                $sub->select(DB::raw(1))
                    ->from('line_version_intervals as i')
                    ->whereColumn('i.line_version_id', 'lv.id')
                    ->whereDate('i.valid_from', '<=', $datum)
                    ->whereDate('i.valid_to', '>=', $datum);
            })
            // Sekunden stehen im Feed immer auf :00, trotzdem als Bereich — so trägt die Abfrage
            // auch eine Zeit mit Sekunden, und der Vergleich bleibt ein Index-freundlicher Textvergleich.
            ->where(function ($q) use ($hhmm): void {
                $q->whereBetween('cst.departure_time', [$hhmm.':00', $hhmm.':59'])
                    ->orWhere(function ($q) use ($hhmm): void {
                        $q->whereNull('cst.departure_time')->whereBetween('cst.arrival_time', [$hhmm.':00', $hhmm.':59']);
                    });
            })
            ->select('ct.id as trip_id', 'cst.stop_id', 'lv.id as line_version_id', DB::raw('coalesce(cst.departure_time, cst.arrival_time) as time'))
            ->get()
            ->map(static fn (object $r): array => [
                'trip_id' => (int) $r->trip_id,
                'stop_id' => (int) $r->stop_id,
                'line_version_id' => (int) $r->line_version_id,
                'time' => (string) $r->time,
            ])
            ->all();
    }

    /**
     * @param  array<int, array{trip_id: int, stop_id: int, line_version_id: int, time: string}>  $kandidaten
     * @return array{0: array<int, array{trip_id: int, stop_id: int, line_version_id: int, time: string}>, 1: string}
     */
    private function narrowByStop(array $kandidaten, string $hafasStop, ?string $stopName): array
    {
        $gelernt = $this->hafasStopMap()[$hafasStop] ?? [];

        if ($gelernt !== []) {
            $amHalt = array_values(array_filter($kandidaten, static fn (array $k): bool => in_array($k['stop_id'], $gelernt, true)));

            if ($amHalt !== []) {
                return [$amHalt, 'sighting'];
            }
        }

        if ($stopName !== null && $stopName !== '') {
            $gesucht = $this->normalizer->normalize($stopName);
            $namen = $this->stopNames->namesFor(array_column($kandidaten, 'stop_id'));
            $amHalt = array_values(array_filter(
                $kandidaten,
                fn (array $k): bool => isset($namen[$k['stop_id']]) && $this->normalizer->normalize($namen[$k['stop_id']]) === $gesucht,
            ));

            return [$amHalt, 'name'];
        }

        return [$kandidaten, 'time-only'];
    }

    /**
     * Nur Fahrten, deren Zielhalt so heißt wie die Richtung auf der Tafel („Magdeburg, Rothensee"
     * = „Rothensee"). Leer, wenn keine passt — dann bleibt es bei der Mehrdeutigkeit.
     *
     * @param  array<int, array{trip_id: int, stop_id: int, line_version_id: int, time: string}>  $kandidaten
     * @return array<int, array{trip_id: int, stop_id: int, line_version_id: int, time: string}>
     */
    private function narrowByDirection(array $kandidaten, string $richtung): array
    {
        $ziele = DB::table('consolidated_trips')
            ->whereIn('id', array_column($kandidaten, 'trip_id'))
            ->pluck('last_stop_id', 'id');
        $namen = $this->stopNames->namesFor($ziele->filter()->map(static fn ($id): int => (int) $id)->values()->all());
        $gesucht = $this->normalizer->normalize($richtung);

        return array_values(array_filter($kandidaten, function (array $k) use ($ziele, $namen, $gesucht): bool {
            $ziel = $namen[(int) ($ziele[$k['trip_id']] ?? 0)] ?? null;

            return $ziel !== null && $this->normalizer->normalize($ziel) === $gesucht;
        }));
    }

    /**
     * HAFAS-ID → Konsolidat-Halte, abgeleitet aus den zugeordneten Sichtungen: Der Halt ihrer Fahrt
     * zu ihrer Soll-Uhrzeit. Abgelehnte Sichtungen zählen nicht — dort kann die Zuordnung falsch sein.
     *
     * @return array<string, array<int, int>>
     */
    private function hafasStopMap(): array
    {
        return Cache::remember(self::STOP_MAP_CACHE_KEY, self::STOP_MAP_TTL_SECONDS, function (): array {
            $sichtungen = DB::table('sightings')
                ->whereNotNull('consolidated_trip_id')
                ->where('status', '!=', SightingStatus::Rejected->value)
                ->select('hafas_stop_id', 'consolidated_trip_id', 'departure_planned')
                ->get();

            if ($sichtungen->isEmpty()) {
                return [];
            }

            $halte = [];

            DB::table('consolidated_stop_times')
                ->whereIn('consolidated_trip_id', $sichtungen->pluck('consolidated_trip_id')->unique()->all())
                ->select('consolidated_trip_id', 'stop_id', 'departure_time', 'arrival_time')
                ->orderBy('consolidated_trip_id')
                ->get()
                ->each(function (object $st) use (&$halte): void {
                    $zeit = substr((string) ($st->departure_time ?? $st->arrival_time), 0, 5);
                    $halte[(int) $st->consolidated_trip_id][$zeit][] = (int) $st->stop_id;
                });

            $karte = [];

            foreach ($sichtungen as $s) {
                $uhr = CarbonImmutable::parse((string) $s->departure_planned)->setTimezone(self::NETWORK_TIMEZONE);
                $treffer = $halte[(int) $s->consolidated_trip_id][$uhr->format('H:i')]
                    ?? $halte[(int) $s->consolidated_trip_id][sprintf('%02d:%s', $uhr->hour + 24, $uhr->format('i'))]
                    ?? [];

                foreach ($treffer as $stopId) {
                    $karte[(string) $s->hafas_stop_id][$stopId] = $stopId;
                }
            }

            return array_map('array_values', $karte);
        });
    }

    /**
     * @return array{found: false, reason: string}
     */
    private function notFound(string $reason): array
    {
        return ['found' => false, 'reason' => $reason];
    }
}
