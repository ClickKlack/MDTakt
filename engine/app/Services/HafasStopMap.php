<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SightingMatch;
use App\Enums\SightingStatus;
use App\Models\MdktRoute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HAFAS-ID → Konsolidat-Halte, gelernt aus den zugeordneten Sichtungen (Stop-Map,
 * INTEGRATION_MDKURSTRACKER §4.3). Keine eigene Tabelle — abgeleitet und zwischengespeichert.
 *
 * Zwei Quellen:
 *
 * 1. **Der ganze Laufweg** (28.09.2026): Eine zugeordnete Sichtung trifft ihre Fahrt über die exakte
 *    Uhrzeitfolge. Der getroffene Laufweg-Abschnitt hat damit genauso viele Halte wie die Fahrt im
 *    Feed, in derselben Reihenfolge — die Halte lassen sich nach Position paaren, auch auf Ringfahrten.
 *    Eine Sichtung lehrt so alle Halte ihrer Fahrt auf einmal.
 * 2. **Der Halt der Sichtung** zu ihrer Soll-Uhrzeit — der Rückfall, falls der Abschnitt nicht
 *    (mehr) aufgeht, etwa weil der Import die Fahrt inzwischen ersetzt hat.
 *
 * Abgelehnte Sichtungen zählen nicht — dort kann die Zuordnung falsch sein.
 */
final class HafasStopMap
{
    private const NETWORK_TIMEZONE = 'Europe/Berlin';

    /** Die Karte ändert sich nur mit neuen Sichtungen — eine Stunde genügt. */
    private const TTL_SECONDS = 3600;

    private const CACHE_KEY = 'course-lookup.hafas-stop-map';

    public function __construct(private readonly SightingMatcher $matcher) {}

    /**
     * @return array<string, array<int, int>>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, fn (): array => $this->build());
    }

    /**
     * @return array<string, array<int, int>>
     */
    private function build(): array
    {
        $sichtungen = DB::table('sightings as s')
            ->join('mdkt_routes as r', 'r.id', '=', 's.mdkt_route_id')
            ->whereNotNull('s.consolidated_trip_id')
            ->whereIn('s.match', [SightingMatch::Matched->value, SightingMatch::MatchedNextVersion->value])
            ->where('s.status', '!=', SightingStatus::Rejected->value)
            ->select('s.hafas_stop_id', 's.line', 's.consolidated_trip_id', 's.departure_planned', 's.mdkt_route_id', 'r.updated_at as route_updated_at')
            ->get();

        if ($sichtungen->isEmpty()) {
            return [];
        }

        $halte = $this->tripStops($sichtungen->pluck('consolidated_trip_id')->unique()->all());
        $karte = [];
        $laufwege = [];

        foreach ($sichtungen as $s) {
            $tripId = (int) $s->consolidated_trip_id;

            // Quelle 2: der Halt der Sichtung zu ihrer Uhrzeit.
            $uhr = CarbonImmutable::parse((string) $s->departure_planned)->setTimezone(self::NETWORK_TIMEZONE);

            foreach ($halte[$tripId] ?? [] as $halt) {
                if ($halt['clock'] === $uhr->format('H:i')) {
                    $karte[(string) $s->hafas_stop_id][$halt['stop_id']] = $halt['stop_id'];
                }
            }

            // Quelle 1: je Laufweg und Fahrt einmal — dieselbe Fahrt wird an vielen Tagen gesichtet.
            $laufwege[$s->mdkt_route_id.':'.$tripId] ??= $s;
        }

        foreach ($laufwege as $s) {
            foreach ($this->routePairs($s, $halte[(int) $s->consolidated_trip_id] ?? []) as [$hafas, $stopId]) {
                $karte[$hafas][$stopId] = $stopId;
            }
        }

        Log::debug('HAFAS stop map built', ['hafas_ids' => count($karte), 'routes' => count($laufwege)]);

        return array_map('array_values', $karte);
    }

    /**
     * Paare (HAFAS-ID, Konsolidat-Halt) aus dem getroffenen Laufweg-Abschnitt. Dauerhaft gemerkt je
     * Laufweg-Stand und Fahrt, damit der stündliche Neuaufbau nicht jede Zuordnung wiederholt.
     *
     * @param  array<int, array{stop_id: int, clock: string}>  $fahrt
     * @return array<int, array{0: string, 1: int}>
     */
    private function routePairs(object $s, array $fahrt): array
    {
        $tripId = (int) $s->consolidated_trip_id;
        $stand = CarbonImmutable::parse((string) $s->route_updated_at)->getTimestamp();
        $schluessel = "course-lookup.route-pairs.{$s->mdkt_route_id}.{$tripId}.{$stand}";

        return Cache::rememberForever($schluessel, function () use ($s, $tripId, $fahrt): array {
            $route = MdktRoute::query()->find((int) $s->mdkt_route_id);

            if ($route === null || $fahrt === []) {
                return [];
            }

            $ergebnis = $this->matcher->match(
                $route,
                (string) $s->line,
                (string) $s->hafas_stop_id,
                CarbonImmutable::parse((string) $s->departure_planned),
            );

            if ($ergebnis->tripId !== $tripId || $ergebnis->hafasStops === null) {
                return [];
            }

            if (count($ergebnis->hafasStops) !== count($fahrt)) {
                Log::debug('Route section does not align with trip', [
                    'mdkt_route_id' => $s->mdkt_route_id,
                    'consolidated_trip_id' => $tripId,
                    'route_stops' => count($ergebnis->hafasStops),
                    'trip_stops' => count($fahrt),
                ]);

                return [];
            }

            return array_map(
                static fn (string $hafas, array $halt): array => [$hafas, $halt['stop_id']],
                $ergebnis->hafasStops,
                $fahrt,
            );
        });
    }

    /**
     * Halte je Fahrt in Feed-Reihenfolge, nur mit Zeit — so wie die Signatur sie zählt.
     *
     * @param  array<int, int|string>  $tripIds
     * @return array<int, array<int, array{stop_id: int, clock: string}>>
     */
    private function tripStops(array $tripIds): array
    {
        $halte = [];

        foreach (array_chunk(array_values($tripIds), 1000) as $teil) {
            DB::table('consolidated_stop_times')
                ->whereIn('consolidated_trip_id', $teil)
                ->select('consolidated_trip_id', 'stop_id', 'departure_time', 'arrival_time')
                ->orderBy('consolidated_trip_id')
                ->orderBy('stop_sequence')
                ->get()
                ->each(function (object $st) use (&$halte): void {
                    $zeit = $st->departure_time ?? $st->arrival_time;

                    if ($zeit === null) {
                        return;
                    }

                    // „24:05" im Feed ist 00:05 auf der Uhr — die Sichtung kennt nur die Uhrzeit.
                    $stunde = (int) substr((string) $zeit, 0, 2) % 24;
                    $halte[(int) $st->consolidated_trip_id][] = [
                        'stop_id' => (int) $st->stop_id,
                        'clock' => sprintf('%02d:%s', $stunde, substr((string) $zeit, 3, 2)),
                    ];
                });
        }

        return $halte;
    }
}
