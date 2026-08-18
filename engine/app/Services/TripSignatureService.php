<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Berechnet die stabile Fahrt-Identität je (Trip, Fahrplantyp) — FAHRPLANPERIODEN §5.1.
 *
 *     Signatur = SHA256(route_short_name | day_type | Abfahrts-HH:MM-Sequenz)
 *
 * **Ohne Halte** (entschieden 18.08.2026): Nur so kann MDKursTracker dieselbe Signatur
 * berechnen — die Gegenseite hat HAFAS-Haltestellen-IDs, keine GTFS-Koordinaten, und der
 * Match wurde in INTEGRATION_MDKURSTRACKER §4.1 bewusst ohne Stop-ID-Crosswalk validiert.
 *
 * Ein Trip kann zu mehreren Typen gehören (ein „täglich"-Service zu allen vier), deshalb
 * je (Trip, Typ) eine Zeile. Die gtfs.de-`trip_id` ist nur ein Zeiger und wird bei jedem
 * Import neu vergeben; die Signatur überlebt.
 */
final class TripSignatureService
{
    public function __construct(
        private readonly ServiceDayResolver $serviceDays,
        private readonly FahrplanTypClassifier $classifier,
    ) {}

    /**
     * Baut `trip_signatures` für den aktuellen Roh-Bestand neu auf.
     *
     * @return int Anzahl geschriebener Zeilen
     */
    public function rebuild(): int
    {
        DB::table('trip_signatures')->delete();

        $window = $this->serviceDays->feedWindow();

        if ($window === null) {
            Log::warning('Signature rebuild without imported calendar');

            return 0;
        }

        $typenJeService = $this->dayTypesPerService($window['from'], $window['to']);
        $sequenzen = $this->departureSequences();

        $rows = [];
        $geschrieben = 0;

        foreach ($this->tripsWithLine() as $trip) {
            $sequenz = $sequenzen[$trip->trip_id] ?? null;

            // Trip ohne stop_times ist keine Fahrt — überspringen statt leer zu hashen.
            if ($sequenz === null) {
                continue;
            }

            foreach ($typenJeService[$trip->service_id] ?? [] as $typ) {
                $rows[] = [
                    'trip_id' => $trip->trip_id,
                    'day_type' => $typ,
                    'signature' => hash('sha256', $trip->route_short_name.'|'.$typ.'|'.$sequenz),
                ];

                if (count($rows) >= 2000) {
                    DB::table('trip_signatures')->insert($rows);
                    $geschrieben += count($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            DB::table('trip_signatures')->insert($rows);
            $geschrieben += count($rows);
        }

        Log::info('Trip signatures rebuilt', [
            'rows' => $geschrieben,
            'window_from' => $window['from']->toDateString(),
            'window_to' => $window['to']->toDateString(),
        ]);

        return $geschrieben;
    }

    /**
     * Welche Fahrplantypen bedient eine service_id im Feed-Fenster? Ein Service kann zu
     * mehreren gehören (Mo-So-Muster), ein Typ ohne Tag im Fenster taucht gar nicht auf.
     *
     * @return array<string, array<int, string>> service_id => day_type-Werte
     */
    private function dayTypesPerService(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $proTag = $this->serviceDays->activeServiceIdsForRange($from->toDateString(), $to->toDateString());

        $result = [];
        foreach ($proTag as $date => $serviceIds) {
            $typ = $this->classifier->classify(CarbonImmutable::parse($date))->value;

            foreach ($serviceIds as $serviceId) {
                $result[$serviceId][$typ] = true;
            }
        }

        return array_map(static fn (array $typen): array => array_keys($typen), $result);
    }

    /**
     * Abfahrts-Sequenz je Trip als „HH:MM,HH:MM,…" in stop_sequence-Reihenfolge.
     * GTFS-Zeiten jenseits 24:00 bleiben unverändert — sie sind Teil der Identität.
     *
     * @return array<string, string>
     */
    private function departureSequences(): array
    {
        $sequenzen = [];

        DB::table('stop_times')
            ->select('trip_id', 'stop_sequence', 'departure_time', 'arrival_time')
            ->orderBy('trip_id')
            ->orderBy('stop_sequence')
            ->chunk(20000, function ($rows) use (&$sequenzen): void {
                foreach ($rows as $row) {
                    $zeit = $row->departure_time ?? $row->arrival_time;

                    if ($zeit === null) {
                        continue;
                    }

                    $sequenzen[$row->trip_id][] = substr((string) $zeit, 0, 5);
                }
            });

        return array_map(static fn (array $zeiten): string => implode(',', $zeiten), $sequenzen);
    }

    /**
     * @return Collection<int, object>
     */
    private function tripsWithLine(): Collection
    {
        return DB::table('trips')
            ->join('routes', 'routes.route_id', '=', 'trips.route_id')
            ->select('trips.trip_id', 'trips.service_id', 'routes.route_short_name')
            ->get();
    }
}
