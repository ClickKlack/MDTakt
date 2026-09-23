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
 *
 * **Eine Fahrt kann mehrere Nachfolger haben** — seit dem 23.09.2026, wenn sie an verschiedenen
 * Tagen gelten (KURSE §3). Die Kanten stehen deshalb als Listen und tragen je ihre Tage. Ohne
 * das überschriebe die zweite Kante die erste, und die Kettenwanderung fände je nach Reihenfolge
 * der Zeilen eine andere Kette.
 */
final class TripChainGraph
{
    /** @var array<int, array<int, array<int, DayRange>>> from_trip_id => to_trip_id => Tage der Kante */
    private array $successors = [];

    /** @var array<int, array<int, array<int, DayRange>>> to_trip_id => from_trip_id => Tage der Kante */
    private array $predecessors = [];

    /** @var array<int, bool> Fahrten, deren `from_trip_id` bereits eine Zeile trägt (Anschluss oder Einrücken) */
    private array $outgoing = [];

    /** @var array<int, bool> Fahrten, deren `to_trip_id` bereits eine Zeile trägt (Anschluss oder Ausrücken) */
    private array $incoming = [];

    /**
     * @param  iterable<object|array{from_trip_id: int|null, to_trip_id: int|null, days?: array<int, DayRange>}>  $rows
     *                                                                                                                   Zeilen aus `trip_links`; `kind` wird nicht gebraucht, es folgt aus den NULL-Spalten.
     *                                                                                                                   `days` sind die Tage der Kante — fehlen sie, gilt sie als immer wirksam.
     */
    public static function fromRows(iterable $rows): self
    {
        $graph = new self;

        foreach ($rows as $row) {
            $daten = (array) $row;

            $von = $daten['from_trip_id'] === null ? null : (int) $daten['from_trip_id'];
            $nach = $daten['to_trip_id'] === null ? null : (int) $daten['to_trip_id'];

            $graph->add($von, $nach, $daten['days'] ?? []);
        }

        return $graph;
    }

    /**
     * Trägt eine geplante Kante nach. Ab jetzt gilt sie für Zyklusprüfung und Belegung, als
     * stünde sie schon in der Datenbank.
     *
     * @param  array<int, DayRange>  $tage
     */
    public function link(int $fromId, int $toId, array $tage = []): void
    {
        $this->add($fromId, $toId, $tage);
    }

    /**
     * @param  array<int, DayRange>  $tage
     */
    private function add(?int $fromId, ?int $toId, array $tage = []): void
    {
        if ($fromId !== null) {
            $this->outgoing[$fromId] = true;
        }

        if ($toId !== null) {
            $this->incoming[$toId] = true;
        }

        if ($fromId !== null && $toId !== null) {
            // Dieselbe Kante zweimal gibt es nicht; träte sie doch auf, gewänne die zuletzt
            // eingetragene Tagesmenge — der Datenbankstand vor der geplanten Kante.
            $this->successors[$fromId][$toId] = $tage;
            $this->predecessors[$toId][$fromId] = $tage;
        }
    }

    /**
     * Die Nachfolger dieser Fahrt — mit `$zeitraum` nur die, deren Kante ihn berührt.
     *
     * @param  array<int, DayRange>|null  $zeitraum
     * @return array<int, int>
     */
    public function successorsOf(int $tripId, ?array $zeitraum = null): array
    {
        return $this->nachbarn($this->successors[$tripId] ?? [], $zeitraum);
    }

    /**
     * @param  array<int, DayRange>|null  $zeitraum
     * @return array<int, int>
     */
    public function predecessorsOf(int $tripId, ?array $zeitraum = null): array
    {
        return $this->nachbarn($this->predecessors[$tripId] ?? [], $zeitraum);
    }

    /**
     * @param  array<int, array<int, DayRange>>  $kanten
     * @param  array<int, DayRange>|null  $zeitraum
     * @return array<int, int>
     */
    private function nachbarn(array $kanten, ?array $zeitraum): array
    {
        if ($zeitraum === null) {
            return array_keys($kanten);
        }

        $treffer = [];

        foreach ($kanten as $id => $tage) {
            // Eine Kante ohne Tagesangabe gilt als immer wirksam — so verhalten sich Graphen,
            // die ohne Gültigkeiten gebaut wurden, wie bisher.
            if ($tage === [] || DayRange::overlap($tage, $zeitraum)) {
                $treffer[] = $id;
            }
        }

        return $treffer;
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
     * Alle Fahrten, die mit dieser über Anschlüsse zusammenhängen — sie selbst eingeschlossen.
     *
     * **Eine Menge, keine Folge.** Solange jede Fahrt höchstens einen Nachfolger hatte, war die
     * Kette eine Kette und ließ sich „Vorgänger zuerst" aufzählen. Seit eine Fahrt je Tag einen
     * anderen Nachfolger haben darf, verzweigt sie sich: Über den Versionswechsel einer
     * Nachbarlinie hinweg gehören beide Zweige demselben Fahrzeug, aber nie demselben Tag. Wer
     * eine Reihenfolge braucht, muss einen Zeitraum vorgeben — dann ist sie wieder eindeutig.
     *
     * @param  array<int, DayRange>|null  $zeitraum  nur Kanten, die ihn berühren
     * @return array<int, int> aufsteigend nach Fahrt-Id, damit dieselbe Kette denselben Wert liefert
     */
    public function chainOf(int $tripId, ?array $zeitraum = null): array
    {
        $gesehen = [$tripId => true];
        $offen = [$tripId];

        while ($offen !== []) {
            $aktuell = array_pop($offen);

            foreach ([...$this->successorsOf($aktuell, $zeitraum), ...$this->predecessorsOf($aktuell, $zeitraum)] as $nachbar) {
                if (isset($gesehen[$nachbar])) {
                    continue;
                }

                $gesehen[$nachbar] = true;
                $offen[] = $nachbar;
            }
        }

        $ids = array_keys($gesehen);
        sort($ids);

        return $ids;
    }

    /**
     * Würde ein Anschluss `$fromId → $toId` einen Ring schließen?
     *
     * Ein Umlauf ist eine Folge, kein Kreis — ein Fahrzeug fährt einen Betriebstag von vorn nach
     * hinten durch. Ohne diese Prüfung wäre die Kette nicht mehr auslesbar.
     *
     * **An einem Tag, nicht über alle hinweg.** Verfolgt werden nur Kanten, die die Tage der
     * neuen berühren. Zwei Kanten, die einander nie begegnen, bilden keinen Ring: Sie gehören zu
     * verschiedenen Fahrplanständen, und das Fahrzeug fährt an keinem Tag im Kreis.
     *
     * In der Praxis ist das eher Absicherung als häufiger Fall: Ein Ring verlangt, dass jedes
     * aufeinanderfolgende Paar gemeinsame Tage hat, und dann überschneiden sich meist auch
     * seine Enden. Die Einschränkung kostet nichts und verhindert, dass ein Anschluss an einem
     * Ring scheitert, den es an keinem Tag gibt.
     *
     * @param  array<int, DayRange>  $tage  Tage der neuen Kante; leer heißt „ohne Einschränkung"
     */
    public function wouldCreateCycle(int $fromId, int $toId, array $tage = []): bool
    {
        if ($fromId === $toId) {
            return true;
        }

        $zeitraum = $tage === [] ? null : $tage;
        $gesehen = [$toId => true];
        $offen = [$toId];

        while ($offen !== []) {
            $aktuell = array_pop($offen);

            foreach ($this->successorsOf($aktuell, $zeitraum) as $danach) {
                if ($danach === $fromId) {
                    return true;
                }

                if (isset($gesehen[$danach])) {
                    continue;
                }

                $gesehen[$danach] = true;
                $offen[] = $danach;
            }
        }

        return false;
    }
}
