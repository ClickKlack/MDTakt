<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\GtfsTime;
use PHPUnit\Framework\TestCase;

/**
 * GTFS-Zeiten sind Betriebstag-Sekunden, keine Uhrzeiten (FAHRPLANPERIODEN §2).
 */
final class GtfsTimeTest extends TestCase
{
    public function test_parses_padded_and_unpadded_hours(): void
    {
        // Die GTFS-Spezifikation erlaubt beide Schreibweisen.
        $this->assertSame(25200, GtfsTime::toSeconds('07:00:00'));
        $this->assertSame(25200, GtfsTime::toSeconds('7:00:00'));
        $this->assertSame(25200, GtfsTime::toSeconds('7:00'));
    }

    public function test_keeps_hours_beyond_midnight(): void
    {
        // 25:30 gehoert zum Betriebstag des Vortags und darf nicht auf 01:30 umgerechnet werden.
        $this->assertSame(91800, GtfsTime::toSeconds('25:30:00'));
        $this->assertSame('25:30', GtfsTime::toClock('25:30:00'));
    }

    public function test_unreadable_values_yield_null(): void
    {
        foreach ([null, '', 'abc', '12:60:00', '12:00:99'] as $wert) {
            $this->assertNull(GtfsTime::toSeconds($wert), var_export($wert, true).' darf nicht geparst werden');
        }
    }

    public function test_clock_pads_the_hour(): void
    {
        $this->assertSame('07:00', GtfsTime::toClock('7:00:00'));
        $this->assertSame('23:50', GtfsTime::toClock('23:50:00'));
        $this->assertNull(GtfsTime::toClock(null));
    }

    public function test_sorting_is_chronological_not_lexical(): void
    {
        $zeiten = ['23:50:00', '7:00:00', '25:10:00', '09:30:00'];
        usort($zeiten, GtfsTime::compare(...));

        // Lexikalisch sortiert stuende "7:00:00" hinter "25:10:00" — genau die Falle.
        $this->assertSame(['7:00:00', '09:30:00', '23:50:00', '25:10:00'], $zeiten);
    }

    public function test_unreadable_values_sort_last(): void
    {
        $zeiten = ['kaputt', '08:00:00'];
        usort($zeiten, GtfsTime::compare(...));

        $this->assertSame(['08:00:00', 'kaputt'], $zeiten);
    }
}
