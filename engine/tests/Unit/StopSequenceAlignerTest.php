<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\StopSequenceAligner;
use PHPUnit\Framework\TestCase;

/**
 * Die Zeilenachse der Fahrplan-Matrix. Halte sind hier Zahlen — der Algorithmus kennt keine
 * Namen, nur Identitäten und Reihenfolge.
 */
final class StopSequenceAlignerTest extends TestCase
{
    private StopSequenceAligner $aligner;

    protected function setUp(): void
    {
        $this->aligner = new StopSequenceAligner;
    }

    /**
     * @param  array<int, array<int, int>>  $folgen
     * @return array{axis: array<int, int>, mapping: array<int, array<int, int>>}
     */
    private function richte(array $folgen, ?array $gewichte = null): array
    {
        $varianten = [];
        foreach ($folgen as $i => $folge) {
            $varianten[$i] = ['stops' => $folge, 'weight' => $gewichte[$i] ?? 1];
        }

        return $this->aligner->align($varianten);
    }

    public function test_identical_sequences_yield_the_same_axis(): void
    {
        $r = $this->richte([[1, 2, 3], [1, 2, 3]]);

        $this->assertSame([1, 2, 3], $r['axis']);
        $this->assertSame([0, 1, 2], $r['mapping'][0]);
        $this->assertSame([0, 1, 2], $r['mapping'][1]);
    }

    public function test_subsequence_leaves_a_gap_at_the_right_place(): void
    {
        // Kurzläufer ohne Halt 2 — er muss die Zeilen 0 und 2 belegen, nicht 0 und 1.
        $r = $this->richte([[1, 2, 3], [1, 3]]);

        $this->assertSame([1, 2, 3], $r['axis']);
        $this->assertSame([0, 2], $r['mapping'][1]);
    }

    public function test_extra_stop_is_inserted_into_the_axis(): void
    {
        $r = $this->richte([[1, 2, 4], [1, 2, 3, 4]]);

        $this->assertSame([1, 2, 3, 4], $r['axis']);
        $this->assertSame([0, 1, 3], $r['mapping'][0], 'Die kürzere Folge überspringt die neue Zeile');
        $this->assertSame([0, 1, 2, 3], $r['mapping'][1]);
    }

    public function test_a_stop_touched_twice_gets_two_rows(): void
    {
        // Wendeschleife: Halt 2 wird zweimal berührt. Beide Berührungen brauchen eine
        // eigene Zeile, sonst ginge eine der beiden Zeiten verloren.
        $r = $this->richte([[1, 2, 3, 2, 4]]);

        $this->assertSame([1, 2, 3, 2, 4], $r['axis']);
        $this->assertSame([0, 1, 2, 3, 4], $r['mapping'][0]);
        $this->assertNotSame($r['mapping'][0][1], $r['mapping'][0][3], 'Zwei Berührungen, zwei Zeilen');
    }

    public function test_loop_variant_aligns_against_a_plain_variant(): void
    {
        // Eine Fahrt fährt die Schleife (2,3,2), die andere nicht.
        $r = $this->richte([[1, 2, 3, 2, 4], [1, 2, 4]], [5, 3]);

        $this->assertSame([1, 2, 3, 2, 4], $r['axis']);
        // Die schleifenlose Fahrt belegt die erste Berührung von Halt 2 und das Ziel.
        $this->assertSame([0, 1, 4], $r['mapping'][1]);
    }

    public function test_most_frequent_variant_shapes_the_axis(): void
    {
        // Die seltenere Variante steht zuerst im Array, die häufigere muss trotzdem führen.
        $r = $this->richte([[1, 9, 5], [1, 2, 5]], [1, 20]);

        $this->assertSame([1, 2, 9, 5], $r['axis'], 'Die häufigste Variante bildet das Gerüst');
        $this->assertSame([0, 1, 3], $r['mapping'][1]);
        $this->assertSame([0, 2, 3], $r['mapping'][0]);
    }

    public function test_alignment_is_deterministic_regardless_of_input_order(): void
    {
        $a = $this->richte([[1, 2, 3], [1, 3, 4], [1, 2, 3, 4]], [3, 2, 1]);
        $b = $this->richte([[1, 2, 3, 4], [1, 3, 4], [1, 2, 3]], [1, 2, 3]);

        $this->assertSame($a['axis'], $b['axis']);
    }

    public function test_contradictory_order_adds_rows_but_never_misplaces_a_stop(): void
    {
        // Zwei Folgen mit gegensätzlicher Reihenfolge von 2 und 3. Der Algorithmus darf sie
        // nicht gewaltsam auf dieselbe Zeile zwingen — lieber eine Zeile mehr.
        $r = $this->richte([[1, 2, 3, 4], [1, 3, 2, 4]]);

        foreach ($r['mapping'] as $variante => $positionen) {
            $folge = [[1, 2, 3, 4], [1, 3, 2, 4]][$variante];

            foreach ($positionen as $position => $zeile) {
                $this->assertSame(
                    $folge[$position],
                    $r['axis'][$zeile],
                    'Jede Zeile muss die Identität tragen, die die Fahrt dort anfährt',
                );
            }

            // Zeilen müssen streng aufsteigen, sonst stünde die Fahrt in der Tabelle rückwärts.
            $this->assertSame($positionen, array_values(array_unique($positionen)));
            $sortiert = $positionen;
            sort($sortiert);
            $this->assertSame($sortiert, $positionen);
        }
    }

    public function test_seven_real_variants_of_line_54_merge_into_one_axis(): void
    {
        // Der Härtefall aus dem Produktivbestand (Linie 54, Mo-Fr, v3). Zahlen stehen für:
        // 1 Bördepark West, 2 Bördepark Ost, 3 Schreberstraße, 4 Reinhard-Mannesmann-Weg,
        // 5 Werner-von-Siemens-Ring, 6 Schäferbreite, 7 Osterweddinger Straße,
        // 8 Wanzleber Chaussee, 9 Eichplatz, 10 Hängelsbreite, 11 Sonnenanger,
        // 12 Auf den Höhen, 13 Birnengarten, 14 Adolf-Jentzen-Straße, 15 Am Teich,
        // 16 Goethepark, 17 Aßmannstraße, 18 Braunlager Str.
        // Beachte: 4 kommt im Stichabsteher zweimal vor, 8 in der Schleife.
        $schluss = [14, 15, 16, 17, 18];
        $abstecher = [4, 5, 4];
        $schleife = [10, 11, 12, 13, 8];

        $r = $this->richte([
            [1, 2, 3, ...$abstecher, 6, 7, 8, ...$schleife, ...$schluss],          // 19 Halte, 1 Fahrt
            [1, 2, 3, 6, 7, 8, 9, ...$schleife, ...$schluss],                       // 17 Halte, 6 Fahrten
            [1, 2, 3, 6, 7, 8, ...$schleife, ...$schluss],                          // 16 Halte, 2 Fahrten
            [1, 2, 3, ...$abstecher, 6, 7, 8, 9, ...$schluss],                      // 15 Halte, 9 Fahrten
            [1, 2, 3, ...$abstecher, 6, 7, 8, ...$schluss],                         // 14 Halte, 16 Fahrten
            [1, 2, 3, 6, 7, 8, 9, ...$schluss],                                     // 12 Halte, 10 Fahrten
            [1, 2, 3, 6, 7, 8, ...$schluss],                                        // 11 Halte, 13 Fahrten
        ], [1, 6, 2, 9, 16, 10, 13]);

        // Alle Einschübe genau einmal: Gerüst (11) + Abstecher (3) + Eichplatz (1) + Schleife (5).
        $this->assertCount(20, $r['axis'], 'Die Achse darf nicht auseinanderlaufen');

        // Und jede Variante muss sich lückenlos und in Reihenfolge einordnen.
        $folgen = [
            [1, 2, 3, ...$abstecher, 6, 7, 8, ...$schleife, ...$schluss],
            [1, 2, 3, 6, 7, 8, 9, ...$schleife, ...$schluss],
            [1, 2, 3, 6, 7, 8, ...$schleife, ...$schluss],
            [1, 2, 3, ...$abstecher, 6, 7, 8, 9, ...$schluss],
            [1, 2, 3, ...$abstecher, 6, 7, 8, ...$schluss],
            [1, 2, 3, 6, 7, 8, 9, ...$schluss],
            [1, 2, 3, 6, 7, 8, ...$schluss],
        ];

        foreach ($folgen as $variante => $folge) {
            $zeilen = $r['mapping'][$variante];
            $this->assertCount(count($folge), $zeilen);

            foreach ($folge as $position => $haltId) {
                $this->assertSame($haltId, $r['axis'][$zeilen[$position]]);
            }

            $sortiert = $zeilen;
            sort($sortiert);
            $this->assertSame($sortiert, $zeilen, "Variante {$variante} läuft rückwärts");
        }
    }

    public function test_empty_input_is_harmless(): void
    {
        $this->assertSame(['axis' => [], 'mapping' => []], $this->aligner->align([]));
        $this->assertSame([], $this->richte([[]])['axis']);
    }
}
