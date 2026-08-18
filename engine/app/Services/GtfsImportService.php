<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GtfsImportStatus;
use App\Models\GtfsImportRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Speichert vom Collector gelieferte GTFS-Stammdaten als vollständigen Ersatz.
 *
 * Roh-GTFS ist eine transiente Quelle: nur der **aktuellste Lauf** wird gehalten.
 * gtfs.de vergibt je Build neue Surrogat-IDs, daher würde ein Upsert je ID die
 * Vorgänger-Zeilen nicht treffen und sie als Waisen zurücklassen. Deshalb wird zu
 * Beginn jedes Laufs der gesamte GTFS-Bestand gelöscht und neu geschrieben.
 * Die periodenübergreifende Konsolidierung ist eine eigene Schicht (FAHRPLANPERIODEN.md).
 */
final class GtfsImportService
{
    /**
     * Maximale Zeilen pro Upsert-Statement (PostgreSQL-Parameter-Limit absichern).
     */
    private const CHUNK_SIZE = 1000;

    public function __construct(
        private readonly TripSignatureService $signatures,
        private readonly ScheduleVersionService $versions,
    ) {}

    /**
     * Startet einen Import-Lauf: legt den Audit-Eintrag an und schreibt die
     * Stammdaten-Basistabellen (routes/stops/trips/calendar/calendar_dates). Die großen
     * stop_times folgen separat per appendStopTimes() (Chunking).
     *
     * @param  array{
     *     routes: array<int, array<string, mixed>>,
     *     stops: array<int, array<string, mixed>>,
     *     trips: array<int, array<string, mixed>>,
     *     calendar?: array<int, array<string, mixed>>,
     *     calendar_dates: array<int, array<string, mixed>>,
     *     feed_info?: array<string, mixed>
     * }  $data
     */
    public function startRun(array $data): GtfsImportRun
    {
        $feedInfo = $data['feed_info'] ?? [];
        $calendar = $data['calendar'] ?? [];

        $counts = [
            'routes' => count($data['routes']),
            'stops' => count($data['stops']),
            'trips' => count($data['trips']),
            'calendar' => count($calendar),
            'calendar_dates' => count($data['calendar_dates']),
            'stop_times' => 0,
        ];

        // Lauf VOR der Transaktion anlegen, damit der Audit-Eintrag ein Rollback überlebt.
        $run = GtfsImportRun::create([
            'status' => GtfsImportStatus::Running,
            'started_at' => Carbon::now(),
            'feed_version' => $feedInfo['feed_version'] ?? null,
            'feed_start_date' => $feedInfo['feed_start_date'] ?? null,
            'feed_end_date' => $feedInfo['feed_end_date'] ?? null,
            'counts' => $counts,
        ]);

        Log::info('GTFS import started', ['run_id' => $run->id] + $counts);

        try {
            // Reihenfolge folgt den Fremdschlüssel-Abhängigkeiten: routes ← trips.
            DB::transaction(function () use ($data, $calendar): void {
                // Vollständig ersetzen — alten Build entfernen, sonst akkumulieren die
                // neuen (volatilen) IDs auf den Vorgängern. stop_times folgen per Chunk.
                $this->clearAllGtfsData();

                $this->upsertRoutes($data['routes']);
                $this->upsertStops($data['stops']);
                $this->upsertTrips($data['trips']);
                $this->upsertCalendar($calendar);
                $this->upsertCalendarDates($data['calendar_dates']);
            });
        } catch (Throwable $e) {
            $this->markFailed($run, $e);

            throw $e;
        }

        return $run->refresh();
    }

    /**
     * Schreibt einen Chunk an stop_times zum laufenden Import und aktualisiert die Zählung.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function appendStopTimes(GtfsImportRun $run, array $rows): GtfsImportRun
    {
        try {
            $this->upsertStopTimes($rows);
        } catch (Throwable $e) {
            $this->markFailed($run, $e);

            throw $e;
        }

        $counts = $run->counts ?? [];
        $counts['stop_times'] = ($counts['stop_times'] ?? 0) + count($rows);
        $run->counts = $counts;
        $run->save();

        Log::debug('GTFS stop_times chunk stored', ['run_id' => $run->id, 'chunk' => count($rows), 'total' => $counts['stop_times']]);

        return $run;
    }

    /**
     * Schließt einen Import-Lauf erfolgreich ab.
     */
    public function finishRun(GtfsImportRun $run): GtfsImportRun
    {
        $counts = ($run->counts ?? []) + $this->consolidate($run);

        $run->update([
            'status' => GtfsImportStatus::Success,
            'finished_at' => Carbon::now(),
            'counts' => $counts,
        ]);

        Log::info('GTFS import finished', ['run_id' => $run->id] + $counts);

        return $run->refresh();
    }

    /**
     * Schreibt Fahrt-Signaturen und Linien-Versionen fort (FAHRPLANPERIODEN §6.2).
     *
     * Läuft nach dem Roh-Import: Der Bestand ist zu diesem Zeitpunkt vollständig geschrieben.
     * Ein Fehler hier macht den Import **nicht** ungültig — die Roh-Daten sind korrekt und die
     * Konsolidierung ist wiederholbar. Sie wird deshalb protokolliert und im Lauf vermerkt,
     * statt den bereits geschriebenen Import als gescheitert zu markieren.
     *
     * @return array<string, mixed>
     */
    private function consolidate(GtfsImportRun $run): array
    {
        try {
            $this->signatures->rebuild();

            return $this->versions->updateFromCurrentImport();
        } catch (Throwable $e) {
            Log::error('Consolidation after import failed', [
                'run_id' => $run->id,
                'exception' => $e->getMessage(),
            ]);

            return ['consolidation_error' => $e->getMessage()];
        }
    }

    /**
     * Löscht den gesamten GTFS-Bestand vor dem Neuschreiben (Reihenfolge folgt
     * den Fremdschlüsseln). sightings.assigned_trip_id wird per nullOnDelete genullt.
     */
    private function clearAllGtfsData(): void
    {
        DB::table('stop_times')->delete();
        DB::table('trips')->delete();
        DB::table('stops')->delete();
        DB::table('routes')->delete();
        DB::table('calendar')->delete();
        DB::table('calendar_dates')->delete();
    }

    private function markFailed(GtfsImportRun $run, Throwable $e): void
    {
        $run->update([
            'status' => GtfsImportStatus::Failed,
            'finished_at' => Carbon::now(),
            'error_message' => $e->getMessage(),
        ]);

        Log::error('GTFS import failed', ['run_id' => $run->id, 'message' => $e->getMessage()]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertRoutes(array $rows): void
    {
        $mapped = array_map(static fn (array $r): array => [
            'route_id' => $r['route_id'],
            'route_short_name' => $r['route_short_name'],
            'route_type' => $r['route_type'],
        ], $rows);

        $this->chunkUpsert('routes', $mapped, ['route_id'], ['route_short_name', 'route_type']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertStops(array $rows): void
    {
        $mapped = array_map(static fn (array $r): array => [
            'stop_id' => $r['stop_id'],
            'stop_name' => $r['stop_name'],
            'lat' => $r['lat'] ?? null,
            'lon' => $r['lon'] ?? null,
        ], $rows);

        $this->chunkUpsert('stops', $mapped, ['stop_id'], ['stop_name', 'lat', 'lon']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertTrips(array $rows): void
    {
        $mapped = array_map(static fn (array $r): array => [
            'trip_id' => $r['trip_id'],
            'route_id' => $r['route_id'],
            'service_id' => $r['service_id'],
            'block_id' => $r['block_id'] ?? null,
            'direction_id' => $r['direction_id'] ?? null,
        ], $rows);

        $this->chunkUpsert('trips', $mapped, ['trip_id'], ['route_id', 'service_id', 'block_id', 'direction_id']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertCalendar(array $rows): void
    {
        $mapped = array_map(static fn (array $r): array => [
            'service_id' => $r['service_id'],
            'monday' => (bool) $r['monday'],
            'tuesday' => (bool) $r['tuesday'],
            'wednesday' => (bool) $r['wednesday'],
            'thursday' => (bool) $r['thursday'],
            'friday' => (bool) $r['friday'],
            'saturday' => (bool) $r['saturday'],
            'sunday' => (bool) $r['sunday'],
            'start_date' => $r['start_date'],
            'end_date' => $r['end_date'],
        ], $rows);

        $this->chunkUpsert('calendar', $mapped, ['service_id'], [
            'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'start_date', 'end_date',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertCalendarDates(array $rows): void
    {
        $mapped = array_map(static fn (array $r): array => [
            'service_id' => $r['service_id'],
            'date' => $r['date'],
            'exception_type' => $r['exception_type'],
        ], $rows);

        $this->chunkUpsert('calendar_dates', $mapped, ['service_id', 'date'], ['exception_type']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertStopTimes(array $rows): void
    {
        $mapped = array_map(static fn (array $r): array => [
            'trip_id' => $r['trip_id'],
            'stop_id' => $r['stop_id'],
            'arrival_time' => $r['arrival_time'] ?? null,
            'departure_time' => $r['departure_time'] ?? null,
            'stop_sequence' => $r['stop_sequence'],
        ], $rows);

        $this->chunkUpsert('stop_times', $mapped, ['trip_id', 'stop_sequence'], ['stop_id', 'arrival_time', 'departure_time']);
    }

    /**
     * Upsert in Blöcken, um das Parameter-Limit von PostgreSQL nicht zu überschreiten.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $uniqueBy
     * @param  array<int, string>  $update
     */
    private function chunkUpsert(string $table, array $rows, array $uniqueBy, array $update): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $update);
        }

        Log::debug('GTFS upsert chunked', ['table' => $table, 'rows' => count($rows)]);
    }
}
