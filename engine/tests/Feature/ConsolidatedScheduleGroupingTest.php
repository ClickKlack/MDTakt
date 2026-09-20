<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Services\ConsolidatedScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Fahrten einer Linie, gruppiert nach Start → Ziel — die Ansicht hinter „Linien" im Admin.
 *
 * Die Gruppierung sortiert entlang des Betriebstags und braucht dafür die Linie. Vorher fehlte
 * sie im `use` der Gruppen-Closure: Jeder Aufruf mit Treffern brach mit „Undefined variable
 * $line" ab, während der leere Fall durchlief und die Lücke verdeckte.
 */
final class ConsolidatedScheduleGroupingTest extends TestCase
{
    use RefreshDatabase;

    private ConsolidatedFixtures $f;

    private ConsolidatedScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new ConsolidatedFixtures;
        $this->service = app(ConsolidatedScheduleService::class);
    }

    public function test_grouped_by_start_end_groups_trips_by_endpoints(): void
    {
        $version = $this->f->version('1');
        $this->f->gueltigkeit($version);

        $this->f->fahrt($version, ['Kannenstieg', 'Zentrum', 'Listemannstr.'], ['06:00:00', '06:10:00', '06:20:00']);
        $this->f->fahrt($version, ['Kannenstieg', 'Zentrum', 'Listemannstr.'], ['07:00:00', '07:10:00', '07:20:00']);
        $this->f->fahrt($version, ['Listemannstr.', 'Zentrum', 'Kannenstieg'], ['06:30:00', '06:40:00', '06:50:00']);

        $ergebnis = $this->service->groupedByStartEnd('1');

        $this->assertSame('1', $ergebnis['line']);
        $this->assertSame(3, $ergebnis['trip_count']);
        $this->assertCount(2, $ergebnis['groups']);

        // Größte Gruppe zuerst — zwei Fahrten Kannenstieg → Listemannstr.
        $groesste = $ergebnis['groups'][0];
        $this->assertSame('Kannenstieg', $groesste['start_stop']);
        $this->assertSame('Listemannstr.', $groesste['end_stop']);
        $this->assertSame(2, $groesste['trip_count']);
        $this->assertSame(
            ['06:00:00', '07:00:00'],
            array_column($groesste['trips'], 'departure_time'),
        );
    }

    /**
     * Der Grenzfall, der die Linie überhaupt erst nötig macht: Auf einer Nachtlinie gehört
     * 05:48 ans Ende des Betriebstags, nicht an seinen Anfang. Ohne die Linie im Sortier-
     * schlüssel stünde die halbe Nacht vorn — der Fehler wäre stumm, nicht laut.
     */
    public function test_grouped_by_start_end_sorts_night_line_along_operating_day(): void
    {
        $version = $this->f->version('N9');
        $this->f->gueltigkeit($version);

        // Bewusst in „falscher" Reihenfolge angelegt, damit nicht die Einfügereihenfolge trägt.
        $this->f->fahrt($version, ['Am Stern', 'Olvenstedter Platz'], ['05:48:00', '06:10:00']);
        $this->f->fahrt($version, ['Am Stern', 'Olvenstedter Platz'], ['23:48:00', '00:10:00']);
        $this->f->fahrt($version, ['Am Stern', 'Olvenstedter Platz'], ['01:48:00', '02:10:00']);

        $ergebnis = $this->service->groupedByStartEnd('N9');

        $this->assertSame(
            ['23:48:00', '01:48:00', '05:48:00'],
            array_column($ergebnis['groups'][0]['trips'], 'departure_time'),
        );
    }

    /**
     * Gegenprobe zur Nachtlinie: Auf einer Tageslinie (Grenze 03:00) liegt 05:48 am Anfang
     * des Betriebstags. Dieselben Zeiten, andere Linie, andere Reihenfolge.
     */
    public function test_grouped_by_start_end_sorts_day_line_along_operating_day(): void
    {
        $version = $this->f->version('1');
        $this->f->gueltigkeit($version);

        $this->f->fahrt($version, ['Am Stern', 'Olvenstedter Platz'], ['23:48:00', '00:10:00']);
        $this->f->fahrt($version, ['Am Stern', 'Olvenstedter Platz'], ['05:48:00', '06:10:00']);
        $this->f->fahrt($version, ['Am Stern', 'Olvenstedter Platz'], ['01:48:00', '02:10:00']);

        $ergebnis = $this->service->groupedByStartEnd('1');

        // 01:48 liegt vor der Grenze 03:00 und gehört damit ans Ende des Vortags-Betriebstags.
        $this->assertSame(
            ['05:48:00', '23:48:00', '01:48:00'],
            array_column($ergebnis['groups'][0]['trips'], 'departure_time'),
        );
    }

    public function test_grouped_by_start_end_carries_version_and_validity(): void
    {
        $version = $this->f->version('1', FahrplanTyp::Sa, 2);
        $this->f->gueltigkeit($version, '2026-08-22', '2026-09-19');
        $this->f->fahrt($version, ['Kannenstieg', 'Listemannstr.'], ['04:32:00', '04:49:00']);

        $fahrt = $this->service->groupedByStartEnd('1')['groups'][0]['trips'][0];

        $this->assertSame('sa', $fahrt['day_type']);
        $this->assertSame(2, $fahrt['version_no']);
        $this->assertSame('2026-08-22', $fahrt['validity'][0]['valid_from']);
        $this->assertSame('2026-09-19', $fahrt['validity'][0]['valid_to']);
    }

    public function test_grouped_by_start_end_returns_empty_result_for_unknown_line(): void
    {
        $version = $this->f->version('1');
        $this->f->gueltigkeit($version);
        $this->f->fahrt($version, ['Kannenstieg', 'Listemannstr.'], ['06:00:00', '06:20:00']);

        $ergebnis = $this->service->groupedByStartEnd('99');

        $this->assertSame(0, $ergebnis['trip_count']);
        $this->assertSame([], $ergebnis['groups']);
    }
}
