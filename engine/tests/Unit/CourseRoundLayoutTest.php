<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CourseRoundLayout;
use App\Services\StopSequenceAligner;
use PHPUnit\Framework\TestCase;

/**
 * Die Achse aus dem Fahrtmuster: ein kleiner Ring aus zwei Linien.
 *
 *   1: Halt 1 → 2 → 3      5: Halt 3 → 4 → 5      5: Halt 5 → 4 → 3      1: Halt 3 → 2 → 1
 *
 * Eine Runde dauert 50 Minuten (06:00 → 06:50).
 */
final class CourseRoundLayoutTest extends TestCase
{
    private const HIN_1 = ['1', [1, 2, 3]];

    private const HIN_5 = ['5', [3, 4, 5]];

    private const RUECK_5 = ['5', [5, 4, 3]];

    private const RUECK_1 = ['1', [3, 2, 1]];

    private function layout(): CourseRoundLayout
    {
        return new CourseRoundLayout(new StopSequenceAligner);
    }

    /**
     * Eine Fahrt im Format der Kursübersicht.
     *
     * @param  array{0: string, 1: array<int, int>}  $weg
     * @return array<string, mixed>
     */
    private function fahrt(array $weg, string $ab, string $an): array
    {
        return [
            'line' => $weg[0],
            'departure_sort' => $this->sekunden($ab),
            'arrival_sort' => $this->sekunden($an),
            'stops' => array_map(static fn (int $id): array => ['stop_id' => $id, 'stop_name' => "Halt {$id}"], $weg[1]),
        ];
    }

    private function sekunden(string $zeit): int
    {
        [$h, $m] = array_map('intval', explode(':', $zeit));

        return $h * 3600 + $m * 60;
    }

    /**
     * Ein Umlauf, der den Ring einmal ganz fährt und dann eine zweite Runde beginnt.
     *
     * @return array<string, mixed>
     */
    private function vollerUmlauf(): array
    {
        return ['trips' => [
            $this->fahrt(self::HIN_1, '06:00', '06:10'),
            $this->fahrt(self::HIN_5, '06:10', '06:20'),
            $this->fahrt(self::RUECK_5, '06:25', '06:35'),
            $this->fahrt(self::RUECK_1, '06:35', '06:45'),
            $this->fahrt(self::HIN_1, '06:50', '07:00'),
        ]];
    }

    public function test_layout_returns_null_without_trips(): void
    {
        $this->assertNull($this->layout()->layout([['trips' => []], ['trips' => []]], '1'));
    }

    /**
     * Die Runde beginnt mit dem häufigsten Muster der gewählten Linie — für die 5 also mit ihrer
     * Fahrt von Halt 3 nach 5, obwohl der Umlauf mit der 1 in den Tag startet.
     */
    public function test_layout_starts_the_round_with_the_selected_line(): void
    {
        $bruchstueck = ['trips' => [$this->fahrt(self::HIN_5, '06:30', '06:40')]];

        [$achse] = $this->layout()->layout([$this->vollerUmlauf(), $bruchstueck], '5');

        $this->assertSame([3, 4, 5], array_slice($achse, 0, 3));
    }

    /**
     * Ein Bruchstück, das mitten im Ring beginnt, steht auf den Zeilen seines Musters — neben
     * derselben Fahrt des vollen Umlaufs, nicht an dessen Anfang.
     */
    public function test_layout_places_a_fragment_beside_the_same_pattern(): void
    {
        // Fährt 20 Minuten hinter dem vollen Umlauf, beginnt aber erst mit der Rückfahrt der 5.
        $bruchstueck = ['trips' => [
            $this->fahrt(self::RUECK_5, '06:45', '06:55'),
            $this->fahrt(self::RUECK_1, '06:55', '07:05'),
        ]];

        [, $zuordnung] = $this->layout()->layout([$this->vollerUmlauf(), $bruchstueck], '1');

        // RUECK_5 steht im vollen Umlauf an Position 6–8, im Bruchstück an 0–2.
        $this->assertSame(
            [$zuordnung[0][6], $zuordnung[0][7], $zuordnung[0][8]],
            [$zuordnung[1][0], $zuordnung[1][1], $zuordnung[1][2]],
        );
    }

    /**
     * Eine Lücke in der Kette bleibt eine Lücke: Die Fahrt nach der Pause steht zwei Runden
     * tiefer, und dazwischen liegt das Gerüst der übersprungenen Runde.
     */
    public function test_layout_keeps_a_gap_as_whole_rounds(): void
    {
        $mitLuecke = ['trips' => [
            $this->fahrt(self::HIN_1, '06:20', '06:30'),
            // Zwei Runden später wieder dieselbe Fahrt.
            $this->fahrt(self::HIN_1, '08:00', '08:10'),
        ]];

        [$achse, $zuordnung] = $this->layout()->layout([$this->vollerUmlauf(), $mitLuecke], '1');

        $rundeLang = 12;
        $this->assertSame(0, intdiv($zuordnung[1][0], $rundeLang), 'Die erste Fahrt steht in der ersten Runde.');
        $this->assertSame(2, intdiv($zuordnung[1][3], $rundeLang), 'Nach der Lücke steht sie zwei Runden tiefer.');
        $this->assertCount(3 * $rundeLang, $achse);
    }

    /**
     * Zwei Fahrten eines Umlaufs landen nie auf denselben Zeilen — auch wenn sie nach der Uhr
     * in dieselbe Runde fielen.
     */
    public function test_layout_never_puts_two_trips_of_a_course_on_the_same_rows(): void
    {
        // Ein Verstärker, der dieselbe Fahrt nach 30 Minuten wiederholt — schneller als der Ring.
        $verstaerker = ['trips' => [
            $this->fahrt(self::HIN_1, '06:05', '06:15'),
            $this->fahrt(self::HIN_1, '06:35', '06:45'),
        ]];

        [, $zuordnung] = $this->layout()->layout([$this->vollerUmlauf(), $verstaerker], '1');

        $this->assertCount(6, array_unique($zuordnung[1]));
        $this->assertLessThan($zuordnung[1][3], $zuordnung[1][2], 'Die Spalte läuft von oben nach unten.');
    }
}
