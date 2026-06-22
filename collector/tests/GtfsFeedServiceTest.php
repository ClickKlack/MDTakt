<?php

declare(strict_types=1);

namespace MdTakt\Collector\Tests;

use InvalidArgumentException;
use MdTakt\Collector\Services\GtfsFeedService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GtfsFeedServiceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/gtfs';

    private function service(string $agencyFilter = 'Magdeburger Verkehrsbetriebe'): GtfsFeedService
    {
        return new GtfsFeedService(new NullLogger(), $agencyFilter);
    }

    public function test_parse_keeps_all_routes_of_configured_agency(): void
    {
        $batch = $this->service()->parseDirectory(self::FIXTURES);

        // Alle MVB-Routen bleiben: Tram (route_id=1, type 0) UND Bus (route_id=B5, type 3).
        // DVB-Tram (route_id=10) fällt über den Agency-Filter raus.
        $this->assertCount(2, $batch['routes']);
        $byId = array_column($batch['routes'], 'route_type', 'route_id');
        $this->assertSame(0, $byId['1']);
        $this->assertSame(3, $byId['B5']);
        $this->assertArrayNotHasKey('10', $byId);
    }

    public function test_parse_cascades_filter_to_trips_stop_times_stops_and_services(): void
    {
        $batch = $this->service()->parseDirectory(self::FIXTURES);

        // Trips der MVB-Routen: T1, T2 (Tram 1) + TB (Bus B5); T10 (DVB) raus.
        $this->assertCount(3, $batch['trips']);
        $this->assertEqualsCanonicalizing(['T1', 'T2', 'TB'], array_column($batch['trips'], 'trip_id'));

        // StopTimes: 2x T1 + 1x T2 + 1x TB = 4; die von T10 sind weg.
        $this->assertCount(4, $batch['stop_times']);

        // Stops: nur S1 + S2 referenziert; S9 (nur von T10) fällt weg.
        $this->assertCount(2, $batch['stops']);
        $this->assertEqualsCanonicalizing(['S1', 'S2'], array_column($batch['stops'], 'stop_id'));

        // Calendar-Dates: nur Service W1; X1 (von T10) fällt weg.
        $this->assertCount(1, $batch['calendar_dates']);
        $this->assertSame('W1', $batch['calendar_dates'][0]['service_id']);
        $this->assertSame('2026-06-22', $batch['calendar_dates'][0]['date']);

        // Calendar (Wochenmuster): nur Service W1; X1 fällt weg.
        $this->assertCount(1, $batch['calendar']);
        $cal = $batch['calendar'][0];
        $this->assertSame('W1', $cal['service_id']);
        $this->assertSame(1, $cal['monday']);
        $this->assertSame(0, $cal['saturday']);
        // GTFS-Datum YYYYMMDD wird zu ISO normalisiert.
        $this->assertSame('2026-01-01', $cal['start_date']);
        $this->assertSame('2026-12-31', $cal['end_date']);
    }

    public function test_parse_normalizes_and_preserves_stop_times(): void
    {
        $batch = $this->service()->parseDirectory(self::FIXTURES);

        $times = [];
        foreach ($batch['stop_times'] as $st) {
            $times[$st['trip_id'] . '#' . $st['stop_sequence']] = $st['departure_time'];
        }

        // Unaufgefuellte Stunde wird zu HH:MM:SS normalisiert.
        $this->assertSame('05:30:00', $times['T1#1']);
        // Zeit nach Mitternacht (> 24:00:00) bleibt erhalten.
        $this->assertSame('25:10:00', $times['T2#1']);
    }

    public function test_normalize_pads_single_digit_hour(): void
    {
        $this->assertSame('05:03:00', $this->service()->normalizeGtfsTime('5:03:00'));
    }

    public function test_normalize_preserves_times_after_midnight(): void
    {
        $this->assertSame('26:45:30', $this->service()->normalizeGtfsTime('26:45:30'));
    }

    public function test_normalize_returns_null_for_empty(): void
    {
        $this->assertNull($this->service()->normalizeGtfsTime(''));
        $this->assertNull($this->service()->normalizeGtfsTime(null));
    }

    public function test_normalize_rejects_malformed_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->normalizeGtfsTime('12:30');
    }

    public function test_empty_agency_filter_keeps_all_routes(): void
    {
        // Ohne Agency-Filter bleiben alle Routen aller Agencies und Verkehrsmittel.
        $batch = $this->service('')->parseDirectory(self::FIXTURES);

        $this->assertCount(3, $batch['routes']);
        $this->assertEqualsCanonicalizing(['1', '10', 'B5'], array_column($batch['routes'], 'route_id'));
    }

    public function test_parse_reads_feed_info_with_normalized_dates(): void
    {
        $batch = $this->service()->parseDirectory(self::FIXTURES);

        $this->assertSame('2026-06-20T03:00', $batch['feed_info']['feed_version']);
        // GTFS-Datum YYYYMMDD wird zu ISO normalisiert.
        $this->assertSame('2026-06-20', $batch['feed_info']['feed_start_date']);
        $this->assertSame('2026-12-13', $batch['feed_info']['feed_end_date']);
    }
}
