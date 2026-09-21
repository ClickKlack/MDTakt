<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\TripLinkService;

/**
 * Die Umlauf-Ketten eines Ausschnitts **im Speicher** — und die Kanten, die ein Lauf erst noch
 * anlegen will.
 *
 * Zwei Gründe, aus denen es diesen Baustein gibt:
 *
 * 1. {@see TripLinkService::chainFor()} fragt je Kettensprung die Datenbank. Für
 *    einen Einzelklick ist das richtig; über 60 Ankünfte oder 120 Fahrplanspalten sind es
 *    tausende Abfragen.
 * 2. Wichtiger: Gegen den Datenbankstand geprüft sieht ein Lauf die **eigenen, erst geplanten**
 *    Paare nicht. Er könnte dieselbe Abfahrt zweimal vergeben oder mit dem zweiten Paar einen
 *    Ring schließen, den das erste eröffnet hat. Beim Schreiben schlüge das als Verstoß gegen
 *    die Unique-Constraints auf, also mit einem Datenbankfehler mitten in der Transaktion statt
 *    mit einer Meldung, die sagt, was nicht geht. {@see link()} trägt geplante Kanten deshalb in
 *    denselben Graphen ein.
 *
 * **Belegt ist nicht dasselbe wie verkettet.** Ein Einrücken (`kind=end`) belegt `from_trip_id`,
 * ohne einen Nachfolger zu haben; ein Ausrücken `to_trip_id`. Für die Kettenwanderung zählen nur
 * echte Anschlüsse, für die Frage „darf hier noch etwas dran?" auch die Betriebsfahrten. Der
 * Graph hält beides getrennt.
 */
final class TripChainGraph
{
    /** @var array<int, int> from_trip_id => to_trip_id, nur echte Anschlüsse */
    private array $successors = [];

    /** @var array<int, int> to_trip_id => from_trip_id */
    private array $predecessors = [];

    /** @var array<int, bool> Fahrten, deren `from_trip_id` bereits eine Zeile trägt (Anschluss oder Einrücken) */
    private array $outgoing = [];

    /** @var array<int, bool> Fahrten, deren `to_trip_id` bereits eine Zeile trägt (Anschluss oder Ausrücken) */
    private array $incoming = [];

    /**
     * @param  iterable<object|array{from_trip_id: int|null, to_trip_id: int|null}>  $rows
     *                                                                                      Zeilen aus `trip_links`; `kind` wird nicht gebraucht, es folgt aus den NULL-Spalten.
     */
    public static function fromRows(iterable $rows): self
    {
        $graph = new self;

        foreach ($rows as $row) {
            $daten = (array) $row;

            $von = $daten['from_trip_id'] === null ? null : (int) $daten['from_trip_id'];
            $nach = $daten['to_trip_id'] === null ? null : (int) $daten['to_trip_id'];

            $graph->add($von, $nach);
        }

        return $graph;
    }

    /**
     * Trägt eine geplante Kante nach. Ab jetzt gilt sie für Zyklusprüfung und Belegung, als
     * stünde sie schon in der Datenbank.
     */
    public function link(int $fromId, int $toId): void
    {
        $this->add($fromId, $toId);
    }

    private function add(?int $fromId, ?int $toId): void
    {
        if ($fromId !== null) {
            $this->outgoing[$fromId] = true;
        }

        if ($toId !== null) {
            $this->incoming[$toId] = true;
        }

        if ($fromId !== null && $toId !== null) {
            $this->successors[$fromId] = $toId;
            $this->predecessors[$toId] = $fromId;
        }
    }

    public function successorOf(int $tripId): ?int
    {
        return $this->successors[$tripId] ?? null;
    }

    public function predecessorOf(int $tripId): ?int
    {
        return $this->predecessors[$tripId] ?? null;
    }

    /** Hängt an dieser Fahrt schon eine Entscheidung darüber, was **danach** kommt? */
    public function hasOutgoingDecision(int $tripId): bool
    {
        return isset($this->outgoing[$tripId]);
    }

    /** Hängt an dieser Fahrt schon eine Entscheidung darüber, was **davor** war? */
    public function hasIncomingDecision(int $tripId): bool
    {
        return isset($this->incoming[$tripId]);
    }

    /**
     * Alle Fahrten der Kette, zu der diese Fahrt gehört — Vorgänger zuerst.
     *
     * Wie {@see TripLinkService::chainFor()}, nur ohne Datenbank. Das Gesehen-Set
     * ist auch hier da: Ein Zyklus aus einem Altbestand darf die Abfrage nicht hängen lassen.
     *
     * @return array<int, int>
     */
    public function chainOf(int $tripId): array
    {
        $rueckwaerts = [];
        $gesehen = [$tripId => true];
        $aktuell = $tripId;

        while (($vorher = $this->predecessorOf($aktuell)) !== null && ! isset($gesehen[$vorher])) {
            array_unshift($rueckwaerts, $vorher);
            $gesehen[$vorher] = true;
            $aktuell = $vorher;
        }

        $vorwaerts = [];
        $aktuell = $tripId;

        while (($danach = $this->successorOf($aktuell)) !== null && ! isset($gesehen[$danach])) {
            $vorwaerts[] = $danach;
            $gesehen[$danach] = true;
            $aktuell = $danach;
        }

        return [...$rueckwaerts, $tripId, ...$vorwaerts];
    }

    /**
     * Würde ein Anschluss `$fromId → $toId` einen Ring schließen?
     *
     * Ein Umlauf ist eine Folge, kein Kreis — ein Fahrzeug fährt einen Betriebstag von vorn nach
     * hinten durch. Ohne diese Prüfung wäre die Kette nicht mehr auslesbar.
     */
    public function wouldCreateCycle(int $fromId, int $toId): bool
    {
        if ($fromId === $toId) {
            return true;
        }

        $aktuell = $toId;
        $gesehen = [$toId => true];

        while (($danach = $this->successorOf($aktuell)) !== null) {
            if ($danach === $fromId || isset($gesehen[$danach])) {
                return true;
            }

            $gesehen[$danach] = true;
            $aktuell = $danach;
        }

        return false;
    }
}
