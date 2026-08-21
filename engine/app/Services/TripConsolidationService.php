<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ConsolidatedTrip;
use App\Models\LineVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Schreibt die Fahrplan-**Inhalte** einer Linien-Version dauerhaft fort
 * (FAHRPLANPERIODEN §5.3, Phase C).
 *
 * Phase B hat festgehalten, **dass** und **wann** sich eine Linie geändert hat — die Fahrten
 * selbst lagen aber weiter nur im Roh-Bestand, den der nächste Import ersetzt. Erst hier
 * überlebt der Fahrplan den Import.
 *
 * **Warum das idempotent ist, ohne Vergleichslogik:** Der Fingerprint einer Version *ist* die
 * sortierte Menge der Fahrt-Signaturen. Trägt eine Version bereits so viele Fahrten, wie der
 * Roh-Bestand für sie hergibt, kann sich am Inhalt nichts geändert haben — sonst wäre es eine
 * andere Version. Ein Folge-Import schreibt deshalb nur für **neue** Versionen.
 */
final class TripConsolidationService
{
    /** Haltzeiten je Insert — Kompromiss aus Roundtrips und Parametergrenze des Treibers. */
    private const INSERT_CHUNK = 1000;

    public function __construct(private readonly ServiceDayResolver $serviceDays) {}

    /**
     * @param  array<int, string>  $repraesentativeTage  line_version_id => Tag im beobachteten Intervall
     * @param  array<string, int>  $stopMap  Roh-`stop_id` => `consolidated_stops.id`
     * @return array{consolidated_trips: int, versions_filled: int}
     */
    public function consolidate(array $repraesentativeTage, array $stopMap): array
    {
        $fahrten = 0;
        $versionen = 0;

        foreach ($repraesentativeTage as $versionId => $tag) {
            $version = LineVersion::query()->find($versionId);

            if ($version === null) {
                continue;
            }

            $geschrieben = $this->fillVersion($version, $tag, $stopMap);

            if ($geschrieben > 0) {
                $fahrten += $geschrieben;
                $versionen++;
            }
        }

        Log::info('Trips consolidated', [
            'versions_filled' => $versionen,
            'trips_written' => $fahrten,
        ]);

        return ['consolidated_trips' => $fahrten, 'versions_filled' => $versionen];
    }

    /**
     * Füllt eine Version mit den Fahrten, die an ihrem repräsentativen Tag verkehren.
     *
     * @param  array<string, int>  $stopMap
     * @return int Zahl der geschriebenen Fahrten (0 = war bereits vollständig)
     */
    private function fillVersion(LineVersion $version, string $tag, array $stopMap): int
    {
        $serviceIds = $this->serviceDays->activeServiceIdsForRange($tag, $tag)[$tag] ?? [];

        if ($serviceIds === []) {
            return 0;
        }

        $rohFahrten = DB::table('trips')
            ->join('routes', 'routes.route_id', '=', 'trips.route_id')
            ->join('trip_signatures', 'trip_signatures.trip_id', '=', 'trips.trip_id')
            ->where('routes.route_short_name', $version->line)
            ->where('trip_signatures.day_type', $version->day_type->value)
            ->whereIn('trips.service_id', $serviceIds)
            ->select('trips.trip_id', 'trip_signatures.signature', 'routes.route_type')
            ->get();

        if ($rohFahrten->isEmpty()) {
            return 0;
        }

        $vorhanden = ConsolidatedTrip::query()->where('line_version_id', $version->id)->count();

        // Bereits vollständig: Der Fingerprint deckt den Inhalt ab, es gibt nichts zu tun.
        if ($vorhanden >= $rohFahrten->count()) {
            return 0;
        }

        return DB::transaction(function () use ($version, $rohFahrten, $stopMap): int {
            // Teilbestand aus einem abgebrochenen Lauf sauber ersetzen statt ergänzen.
            ConsolidatedTrip::query()->where('line_version_id', $version->id)->delete();

            $zeiten = DB::table('stop_times')
                ->whereIn('trip_id', $rohFahrten->pluck('trip_id'))
                ->orderBy('trip_id')
                ->orderBy('stop_sequence')
                ->get()
                ->groupBy('trip_id');

            $geschrieben = 0;
            $puffer = [];

            foreach ($rohFahrten as $roh) {
                $halte = $zeiten->get($roh->trip_id, collect());

                if ($halte->isEmpty()) {
                    continue;
                }

                $zeilen = [];
                foreach ($halte as $halt) {
                    $konsolidierterHalt = $stopMap[$halt->stop_id] ?? null;

                    if ($konsolidierterHalt === null) {
                        Log::warning('Stop time references an unconsolidated stop', [
                            'trip_id' => $roh->trip_id,
                            'stop_id' => $halt->stop_id,
                        ]);

                        continue;
                    }

                    $zeilen[] = [
                        'stop_id' => $konsolidierterHalt,
                        'stop_sequence' => $halt->stop_sequence,
                        'arrival_time' => $halt->arrival_time,
                        'departure_time' => $halt->departure_time,
                    ];
                }

                if ($zeilen === []) {
                    continue;
                }

                // Steige sind verschmolzen — dieselbe Halt-Identität kann in einer Fahrt
                // mehrfach vorkommen (Wendeschleife). Die Sequenz hält sie auseinander.
                $fahrt = ConsolidatedTrip::query()->create([
                    'line_version_id' => $version->id,
                    'signature' => $roh->signature,
                    'route_type' => $roh->route_type,
                    'first_stop_id' => $zeilen[0]['stop_id'],
                    'last_stop_id' => $zeilen[count($zeilen) - 1]['stop_id'],
                ]);

                foreach ($zeilen as $zeile) {
                    $puffer[] = $zeile + ['consolidated_trip_id' => $fahrt->id];
                }

                // Gebündelt schreiben statt je Fahrt: Der erste produktive Lauf legt rund
                // 230.000 Haltzeiten an, und die Konsolidierung läuft im Request des
                // Import-Abschlusses — auf Shared Hosting zählt jede Roundtrip-Ersparnis
                // gegen `max_execution_time`.
                if (count($puffer) >= self::INSERT_CHUNK) {
                    DB::table('consolidated_stop_times')->insert($puffer);
                    $puffer = [];
                }

                $geschrieben++;
            }

            if ($puffer !== []) {
                DB::table('consolidated_stop_times')->insert($puffer);
            }

            return $geschrieben;
        });
    }
}
