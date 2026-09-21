<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TripChainGraph;
use PHPUnit\Framework\TestCase;

/**
 * Der Kettengraph im Speicher.
 *
 * Er trägt zwei Aussagen, die der Datenbankstand allein nicht liefert: Er kennt die **erst
 * geplanten** Kanten eines Mengen-Laufs, und er hält „belegt" von „verkettet" auseinander — ein
 * Ein- oder Ausrücken belegt einen Anschlussplatz, ohne einen Nachfolger zu haben.
 */
final class TripChainGraphTest extends TestCase
{
    /**
     * @param  array<int, array{from_trip_id: int|null, to_trip_id: int|null}>  $zeilen
     */
    private function graph(array $zeilen = []): TripChainGraph
    {
        return TripChainGraph::fromRows($zeilen);
    }

    public function test_chain_of_an_unlinked_trip_is_the_trip_itself(): void
    {
        $this->assertSame([7], $this->graph()->chainOf(7));
    }

    public function test_chain_runs_forwards_and_backwards(): void
    {
        $graph = $this->graph([
            ['from_trip_id' => 1, 'to_trip_id' => 2],
            ['from_trip_id' => 2, 'to_trip_id' => 3],
        ]);

        $this->assertSame([1, 2, 3], $graph->chainOf(2));
        $this->assertSame([1, 2, 3], $graph->chainOf(1));
        $this->assertSame([1, 2, 3], $graph->chainOf(3));
    }

    public function test_a_terminal_decision_occupies_a_slot_without_a_partner(): void
    {
        // Einrücken: `from_trip_id` belegt, kein Nachfolger. Ausrücken spiegelbildlich.
        $graph = $this->graph([
            ['from_trip_id' => 1, 'to_trip_id' => null],
            ['from_trip_id' => null, 'to_trip_id' => 2],
        ]);

        $this->assertTrue($graph->hasOutgoingDecision(1));
        $this->assertNull($graph->successorOf(1));
        $this->assertSame([1], $graph->chainOf(1));

        $this->assertTrue($graph->hasIncomingDecision(2));
        $this->assertNull($graph->predecessorOf(2));
    }

    public function test_a_planned_link_is_visible_to_later_checks(): void
    {
        $graph = $this->graph();

        $this->assertFalse($graph->hasOutgoingDecision(1));

        $graph->link(1, 2);

        // Genau dafür gibt es den Graphen: Ohne die geplante Kante vergäbe ein Lauf dieselbe
        // Abfahrt ein zweites Mal und liefe beim Schreiben in den Unique-Constraint.
        $this->assertTrue($graph->hasOutgoingDecision(1));
        $this->assertTrue($graph->hasIncomingDecision(2));
        $this->assertSame([1, 2], $graph->chainOf(1));
    }

    public function test_a_planned_link_closes_a_ring_that_the_database_alone_would_miss(): void
    {
        // In der Datenbank steht nur 2→3. Der Lauf plant 3→1 und danach 1→2; erst zusammen
        // ergibt das einen Ring, und nur der Graph sieht beides.
        $graph = $this->graph([['from_trip_id' => 2, 'to_trip_id' => 3]]);

        $this->assertFalse($graph->wouldCreateCycle(3, 1));
        $graph->link(3, 1);

        $this->assertTrue($graph->wouldCreateCycle(1, 2));
    }

    public function test_a_trip_cannot_link_to_itself(): void
    {
        $this->assertTrue($this->graph()->wouldCreateCycle(4, 4));
    }

    public function test_a_corrupt_ring_does_not_hang_the_traversal(): void
    {
        // Aus einem Altbestand: Die Unique-Constraints schließen das heute aus, aber eine
        // Endlosschleife beim Auslesen wäre der schlechtere Umgang damit.
        $graph = $this->graph([
            ['from_trip_id' => 1, 'to_trip_id' => 2],
            ['from_trip_id' => 2, 'to_trip_id' => 1],
        ]);

        $this->assertCount(2, $graph->chainOf(1));
        $this->assertTrue($graph->wouldCreateCycle(1, 2));
    }
}
