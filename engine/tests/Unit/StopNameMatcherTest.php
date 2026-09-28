<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\StopNameMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HAFAS-Name (Tracker) gegen GTFS-Name (Feed) — die Paare stammen aus dem ersten produktiven Lauf.
 */
final class StopNameMatcherTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gleicheHalte(): array
    {
        return [
            'Bhf. ausgeschrieben' => ['Magdeburg, S-Bahnhof Eichenweiler', 'Magdeburg, S-Bhf. Eichenweiler'],
            'Bf. ausgeschrieben' => ['Magdeburg, S-Bahnhof Buckau/Puppentheater', 'S-Bf. Buckau / Puppentheater'],
            'Hbf ohne Punkt' => ['Magdeburg, Hauptbahnhof/Willy-Brandt-Platz', 'Hbf / Willy-Brandt-Platz'],
            'Kürzel mit Bindestrich' => ['Magdeburg, Benediktinerstr./Gesellschaftshaus', 'Benediktinerstr./Ges.-Haus'],
            'zwei Kürzel' => ['Magdeburg, Halberstädter Str./Leipziger Str.', 'Halberst. Str./Leipz. Str.'],
            'Kürzel im Namen' => ['Magdeburg, Hochschule Magdeburg-Stendal', 'Hochschule Magd.-Stendal'],
            'u. für und' => ['Magdeburg, Industrie- und Logistik-Centrum', 'Industrie- u. Logistik-Centrum'],
            'Kürzel am Ende' => ['Magdeburg, Turmschanzenstr./Friedensbrücke', 'Turmschanzenstr./Friedensbr.'],
            'Verkehrsmittel-Zusatz' => ['Magdeburg, Barleber See (Tram/Bus)', 'Barleber See'],
            'nur Tram' => ['Magdeburg, Herrenkrug (Tram)', 'Herrenkrug'],
            'Stadtteil vorn' => ['Magdeburg, Sudenburg, Braunlager Str.', 'Braunlager Straße'],
            'Str. wie bisher' => ['Magdeburg, Kastanienstr.', 'Kastanienstraße'],
        ];
    }

    #[DataProvider('gleicheHalte')]
    public function test_matches_same_stop(string $hafas, string $gtfs): void
    {
        $this->assertTrue((new StopNameMatcher)->matches($hafas, $gtfs));
    }

    public function test_matches_keeps_distinguishing_brackets_apart(): void
    {
        $m = new StopNameMatcher;

        $this->assertFalse($m->matches('Magdeburg, Rothensee', 'Rothensee (Schleife)'));
        $this->assertFalse($m->matches('Magdeburg, Opernhaus (Listemannstr.)', 'Opernhaus'));
        // Erst in der lockeren Stufe fällt der Klammerzusatz weg.
        $this->assertTrue($m->matches('Magdeburg, Opernhaus (Listemannstr.)', 'Opernhaus', true));
    }

    public function test_matches_different_stops(): void
    {
        $m = new StopNameMatcher;

        $this->assertFalse($m->matches('Magdeburg, Kastanienstr.', 'Kastanienweg'));
        $this->assertFalse($m->matches('Magdeburg, S-Bahnhof Neustadt (Tram)', 'S-Bahnhof Südost'));
        $this->assertFalse($m->matches('Magdeburg, Hauptbahnhof', 'Bahnhof Herrenkrug'));
    }
}
