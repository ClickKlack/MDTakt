<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Enums\RouteType;
use App\Models\LineColor;
use App\Models\LineVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Beantwortet Fahrplan-Fragen aus dem **Konsolidat** statt aus dem Roh-Bestand
 * (FAHRPLANPERIODEN Phase C).
 *
 * Der Unterschied ist nicht kosmetisch: Der Roh-Bestand kennt nur das aktuelle Feed-Fenster
 * (rund drei Wochen) und wird bei jedem Import ersetzt. Das Konsolidat antwortet für **jedes
 * Datum, das je beobachtet wurde** — auch für vergangene Baustellenfahrpläne, die im Feed
 * längst nicht mehr stehen.
 *
 * Der Preis: Antworten beruhen teils auf gesicherten, teils auf offenen Grenzen (§5.4 b).
 * Für ein Datum, das in keinem beobachteten Intervall liegt, gibt es hier nichts — und das
 * ist die ehrliche Antwort, keine Lücke im Code.
 */
final class ConsolidatedScheduleService
{
    public function __construct(private readonly FahrplanTypClassifier $classifier) {}

    /**
     * Linienverzeichnis aus dem Konsolidat — gleiche Form wie {@see LineDirectoryService},
     * damit die Frontends nicht zwei Auswertungen brauchen.
     *
     * `route_ids` bleibt leer: GTFS-Route-IDs sind eine Eigenschaft des Roh-Bestands und
     * werden pro Build neu vergeben. Im Konsolidat gibt es sie bewusst nicht mehr.
     *
     * @return Collection<int, array{route_short_name: string, route_type: int, mode: string, modes: array<int, string>, route_ids: array<int, string>, color: string|null}>
     */
    public function lines(): Collection
    {
        $rows = DB::table('consolidated_trips as ct')
            ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
            ->select('lv.line', 'ct.route_type', DB::raw('count(*) as trip_count'))
            ->groupBy('lv.line', 'ct.route_type')
            ->get();

        // Linienfarben liegen auf `route_short_name` — dem Linien-Schlüssel des Systems.
        $farben = LineColor::query()->pluck('color', 'route_short_name');

        return $rows
            ->groupBy('line')
            ->map(function (Collection $proLinie, string $line) use ($farben): array {
                // Prägend ist das Verkehrsmittel mit den meisten Fahrten; bei Gleichstand
                // der kleinere route_type (Tram vor Bus), damit die Anzeige nicht springt.
                $primaer = $proLinie
                    ->sort(fn (object $a, object $b): int => ($b->trip_count <=> $a->trip_count) ?: ($a->route_type <=> $b->route_type))
                    ->first();

                return [
                    'route_short_name' => $line,
                    'route_type' => (int) $primaer->route_type,
                    'mode' => RouteType::modeFor((int) $primaer->route_type),
                    'modes' => $proLinie
                        ->map(static fn (object $r): string => RouteType::modeFor((int) $r->route_type))
                        ->unique()->sort()->values()->all(),
                    'route_ids' => [],
                    'color' => $farben[$line] ?? null,
                ];
            })
            ->sortBy([['route_type', 'asc'], ['route_short_name', 'asc']])
            ->values();
    }

    /**
     * Fahrten des Konsolidats für einen Betriebstag, optional nach Linie und Halt gefiltert.
     *
     * `$stop` ist hier eine **`consolidated_stops.id`**, keine GTFS-`stop_id` — die volatile
     * Roh-ID hat im Konsolidat keine Bedeutung mehr.
     *
     * @param  array{date?: string|null, line?: string|null, stop?: string|null}  $criteria
     * @return array<int, array<string, mixed>>
     */
    public function trips(array $criteria): array
    {
        $date = $criteria['date'] ?? null;
        $line = $criteria['line'] ?? null;
        $stop = $criteria['stop'] ?? null;

        $query = DB::table('consolidated_trips as ct')
            ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
            ->select(
                'ct.id', 'ct.signature', 'ct.route_type', 'ct.first_stop_id', 'ct.last_stop_id',
                'lv.line', 'lv.day_type', 'lv.version_no', 'lv.period_id',
            );

        if ($date !== null) {
            $tag = CarbonImmutable::parse($date);

            // Der Betriebstag bestimmt den Fahrplantyp; gültig ist die Version, deren
            // beobachtetes Intervall diesen Tag einschließt.
            $query->where('lv.day_type', $this->classifier->classify($tag)->value)
                ->whereExists(function ($sub) use ($date): void {
                    $sub->select(DB::raw(1))
                        ->from('line_version_intervals as i')
                        ->whereColumn('i.line_version_id', 'lv.id')
                        ->whereDate('i.valid_from', '<=', $date)
                        ->whereDate('i.valid_to', '>=', $date);
                });
        }

        if ($line !== null) {
            $query->where('lv.line', $line);
        }

        if ($stop !== null) {
            $query->whereExists(function ($sub) use ($stop): void {
                $sub->select(DB::raw(1))
                    ->from('consolidated_stop_times as cst')
                    ->whereColumn('cst.consolidated_trip_id', 'ct.id')
                    ->where('cst.stop_id', $stop);
            });
        }

        $fahrten = $query->orderBy('lv.line')->orderBy('ct.id')->get();
        $zeiten = $this->departureAndArrival($fahrten->pluck('id')->all());
        $namen = $this->stopNames();

        Log::debug('Consolidated trip filter executed', [
            'date' => $date,
            'line' => $line,
            'stop' => $stop,
            'result_count' => $fahrten->count(),
        ]);

        return $fahrten->map(fn (object $f): array => [
            'id' => (int) $f->id,
            'signature' => $f->signature,
            'route_short_name' => $f->line,
            'route_type' => (int) $f->route_type,
            'mode' => RouteType::modeFor((int) $f->route_type),
            'day_type' => $f->day_type,
            'version_no' => (int) $f->version_no,
            'start_stop' => $namen[$f->first_stop_id] ?? null,
            'end_stop' => $namen[$f->last_stop_id] ?? null,
            'departure_time' => $zeiten[$f->id]['departure'] ?? null,
            'arrival_time' => $zeiten[$f->id]['arrival'] ?? null,
        ])->all();
    }

    /**
     * Fahrten einer Linie aus dem Konsolidat, gruppiert nach Start → Ziel — das Gegenstück
     * zu {@see LineTripService::groupedByStartEnd()} für die Admin-Ansicht.
     *
     * Statt Wochenmuster und Einzelterminen (Roh-Begriffe aus `calendar`) trägt jede Fahrt
     * hier ihre **Version** und deren beobachtete Gültigkeit.
     *
     * @return array<string, mixed>
     */
    public function groupedByStartEnd(string $line, ?FahrplanTyp $dayType = null): array
    {
        $versionen = LineVersion::query()
            ->where('line', $line)
            ->when($dayType !== null, fn ($q) => $q->where('day_type', $dayType->value))
            ->with(['intervals' => fn ($q) => $q->orderBy('valid_from')])
            ->get();

        if ($versionen->isEmpty()) {
            return $this->emptyResult($line, $dayType);
        }

        $fahrten = DB::table('consolidated_trips as ct')
            ->whereIn('ct.line_version_id', $versionen->pluck('id'))
            ->select('ct.id', 'ct.signature', 'ct.route_type', 'ct.first_stop_id', 'ct.last_stop_id', 'ct.line_version_id')
            ->orderBy('ct.id')
            ->get();

        if ($fahrten->isEmpty()) {
            return $this->emptyResult($line, $dayType);
        }

        $zeiten = $this->departureAndArrival($fahrten->pluck('id')->all());
        $namen = $this->stopNames();
        $versionInfo = $versionen->keyBy('id');

        $gruppen = $fahrten
            ->groupBy(static fn (object $f): string => $f->first_stop_id.'>'.$f->last_stop_id)
            ->map(function (Collection $gruppe) use ($zeiten, $namen, $versionInfo): array {
                $erste = $gruppe->first();

                return [
                    'start_stop' => $namen[$erste->first_stop_id] ?? '—',
                    'end_stop' => $namen[$erste->last_stop_id] ?? '—',
                    'trip_count' => $gruppe->count(),
                    'trips' => $gruppe
                        ->map(function (object $f) use ($zeiten, $versionInfo): array {
                            $version = $versionInfo->get($f->line_version_id);

                            return [
                                'id' => (int) $f->id,
                                'signature' => $f->signature,
                                'mode' => RouteType::modeFor((int) $f->route_type),
                                'day_type' => $version?->day_type->value,
                                'version_no' => $version?->version_no,
                                'validity' => $version?->intervals->map(fn ($i): array => [
                                    'valid_from' => $i->valid_from->toDateString(),
                                    'valid_to' => $i->valid_to->toDateString(),
                                    'from_confirmed' => (bool) $i->from_confirmed,
                                    'to_confirmed' => (bool) $i->to_confirmed,
                                ])->values()->all() ?? [],
                                'departure_time' => $zeiten[$f->id]['departure'] ?? null,
                                'arrival_time' => $zeiten[$f->id]['arrival'] ?? null,
                            ];
                        })
                        ->sortBy('departure_time')
                        ->values()
                        ->all(),
                ];
            })
            ->sortByDesc('trip_count')
            ->values()
            ->all();

        return [
            'line' => $line,
            'trip_count' => $fahrten->count(),
            'day_type' => $dayType?->value,
            'day_type_label' => $dayType?->label(),
            'reference_date' => null,
            'modes' => $fahrten
                ->map(static fn (object $f): string => RouteType::modeFor((int) $f->route_type))
                ->unique()->sort()->values()->all(),
            'groups' => $gruppen,
        ];
    }

    /**
     * Erste Abfahrt und letzte Ankunft je Fahrt.
     *
     * @param  array<int, int>  $tripIds
     * @return array<int, array{departure: string|null, arrival: string|null}>
     */
    private function departureAndArrival(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $zeiten = [];

        foreach (array_chunk($tripIds, 1000) as $teil) {
            $rows = DB::table('consolidated_stop_times')
                ->whereIn('consolidated_trip_id', $teil)
                ->select(
                    'consolidated_trip_id',
                    DB::raw('min(stop_sequence) as first_seq'),
                    DB::raw('max(stop_sequence) as last_seq'),
                )
                ->groupBy('consolidated_trip_id')
                ->get()
                ->keyBy('consolidated_trip_id');

            $details = DB::table('consolidated_stop_times')
                ->whereIn('consolidated_trip_id', $teil)
                ->select('consolidated_trip_id', 'stop_sequence', 'departure_time', 'arrival_time')
                ->get();

            foreach ($details as $zeile) {
                $grenzen = $rows->get($zeile->consolidated_trip_id);

                if ($grenzen === null) {
                    continue;
                }

                if ((int) $zeile->stop_sequence === (int) $grenzen->first_seq) {
                    $zeiten[$zeile->consolidated_trip_id]['departure'] = $zeile->departure_time;
                }
                if ((int) $zeile->stop_sequence === (int) $grenzen->last_seq) {
                    $zeiten[$zeile->consolidated_trip_id]['arrival'] = $zeile->arrival_time;
                }
            }
        }

        return $zeiten;
    }

    /**
     * Aktueller Name je Halt — die jüngste Attribut-Version gewinnt („latest wins", §5.1).
     *
     * @return array<int, string>
     */
    private function stopNames(): array
    {
        return DB::table('consolidated_stop_versions as v')
            ->orderBy('v.consolidated_stop_id')
            ->orderBy('v.valid_to')
            ->get(['v.consolidated_stop_id', 'v.name'])
            ->keyBy('consolidated_stop_id')
            ->map(static fn (object $r): string => $r->name)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $line, ?FahrplanTyp $dayType): array
    {
        return [
            'line' => $line,
            'trip_count' => 0,
            'day_type' => $dayType?->value,
            'day_type_label' => $dayType?->label(),
            'reference_date' => null,
            'modes' => [],
            'groups' => [],
        ];
    }
}
