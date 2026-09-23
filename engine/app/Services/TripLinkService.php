<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RouteType;
use App\Enums\TripLinkKind;
use App\Http\Requests\TripLinkRequest;
use App\Models\ConsolidatedTrip;
use App\Models\Depot;
use App\Models\TripLink;
use App\Support\DayRange;
use App\Support\TripChainGraph;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Die Umlauf-Kette: Anschlüsse zwischen Fahrten und die bewusste Entscheidung, eine Kette
 * ohne Anschluss beginnen oder enden zu lassen (KURSE §1).
 *
 * Der Dienst legt die Entscheidung an und liest Ketten aus; die **Zulässigkeit** einer
 * Verknüpfung prüft {@see TripLinkRequest}, damit ein Verstoß im
 * einheitlichen 422-Envelope landet statt als Exception.
 */
final class TripLinkService
{
    public function __construct(
        private readonly ConsolidatedTripInfoResolver $tripInfo,
        private readonly DepotService $depots,
        private readonly TripLinkValidity $validity,
    ) {}

    /**
     * Die Entscheidung in Antwortform — samt Hinweisen, die sie nicht verhindern.
     *
     * Eine knappe Wendezeit und ein Linienwechsel sind **keine** Fehler: Der Linienwechsel ist
     * sogar der Normalfall einer Fahrzeugkette (KURSE §2 K1), und wie lange ein Fahrzeug an
     * einer Endstelle wirklich braucht, weiß der Pflegende besser als die Konfiguration. Beides
     * soll aber auffallen, damit ein Fehlgriff nicht stillschweigend stehen bleibt.
     *
     * @return array<string, mixed>
     */
    public function describe(TripLink $link): array
    {
        $info = $this->tripInfo->forIds(array_filter([$link->from_trip_id, $link->to_trip_id]));

        $von = $link->from_trip_id === null ? null : ($info[$link->from_trip_id] ?? null);
        $nach = $link->to_trip_id === null ? null : ($info[$link->to_trip_id] ?? null);

        $wendezeit = $this->turnaroundFrom($von, $nach);

        $hinweise = [];

        $schwelle = (int) config('mdtakt.courses.min_turnaround_minutes') * 60;

        if ($wendezeit !== null && $wendezeit >= 0 && $wendezeit < $schwelle) {
            $hinweise[] = [
                'code' => 'short_turnaround',
                'message' => sprintf(
                    'Die Wendezeit beträgt nur %d Minuten.',
                    intdiv($wendezeit, 60),
                ),
            ];
        }

        if ($von !== null && $nach !== null && $von['line'] !== $nach['line']) {
            $hinweise[] = [
                'code' => 'line_change',
                'message' => sprintf(
                    'Die Kette wechselt von Linie %s auf Linie %s.',
                    $von['line'],
                    $nach['line'],
                ),
            ];
        }

        return [
            'id' => $link->id,
            'kind' => $link->kind->value,
            'stop_id' => $link->stop_id,
            'from_trip' => $von,
            'to_trip' => $nach,
            'turnaround_seconds' => $wendezeit,
            'depot' => $this->describeDepot($link),
            'note' => $link->note,
            'warnings' => $hinweise,
        ];
    }

    /**
     * Legt eine Entscheidung an. Der Übergangshalt ergibt sich aus den beteiligten Fahrten —
     * er wird nicht vom Aufrufer bestimmt, damit er nicht von ihnen abweichen kann.
     */
    public function create(
        TripLinkKind $kind,
        ?ConsolidatedTrip $from,
        ?ConsolidatedTrip $to,
        ?string $note = null,
        ?int $depotId = null,
        bool $depotAngegeben = false,
    ): TripLink {
        $stopId = $kind === TripLinkKind::Start
            ? $to?->first_stop_id
            : $from?->last_stop_id;

        $hof = $this->resolveDepot($kind, $from, $to, $stopId, $depotId, $depotAngegeben);

        $link = TripLink::query()->create([
            'from_trip_id' => $from?->id,
            'to_trip_id' => $to?->id,
            'stop_id' => $stopId,
            'kind' => $kind,
            'depot_id' => $hof,
            'note' => $note,
        ]);

        Log::info('Trip link created', [
            'trip_link_id' => $link->id,
            'kind' => $kind->value,
            'from_trip_id' => $from?->id,
            'to_trip_id' => $to?->id,
            'stop_id' => $stopId,
            'depot_id' => $link->depot_id,
            'depot_auto' => $hof !== null && ! $depotAngegeben,
        ]);

        return $link;
    }

    /**
     * Welcher Betriebshof an dieser Entscheidung steht.
     *
     * Drei Fälle in dieser Reihenfolge:
     *
     * 1. **Ein Anschluss führt zu keinem Hof** — das Fahrzeug fährt weiter, es rückt nicht ein.
     *    Eine mitgeschickte Angabe wird hier verworfen; der Request weist sie ohnehin ab.
     * 2. **Der Aufrufer hat sich geäußert** — dann gilt das, auch das ausdrückliche `null`.
     * 3. **Sonst die Haltestelle fragen.** Wer an der Schleswiger Straße ausrücken lässt, meint
     *    Westerhüsen; das von Hand nachzuklicken wäre Arbeit, die aus der Zuordnung schon folgt.
     *    Ergibt sich nichts Eindeutiges, bleibt der Hof offen — {@see DepotService::suggestFor()}.
     */
    private function resolveDepot(
        TripLinkKind $kind,
        ?ConsolidatedTrip $from,
        ?ConsolidatedTrip $to,
        ?int $stopId,
        ?int $depotId,
        bool $depotAngegeben,
    ): ?int {
        if ($kind === TripLinkKind::Link) {
            return null;
        }

        if ($depotAngegeben) {
            return $depotId;
        }

        $fahrt = $kind === TripLinkKind::Start ? $to : $from;

        if ($fahrt === null) {
            return null;
        }

        return $this->depots->suggestFor($stopId, RouteType::modeFor($fahrt->route_type))?->id;
    }

    /**
     * Den Betriebshof einer Betriebsfahrt setzen oder wieder offen lassen.
     *
     * Getrennt vom Anlegen, weil es die übliche Reihenfolge ist: Erst wird markiert — das ist
     * die Aussage, die zählt —, und der Hof kommt dazu, sobald er feststeht. `null` nimmt ihn
     * wieder heraus; das ist kein Rückschritt, sondern die ehrliche Angabe „noch offen".
     */
    public function setDepot(TripLink $link, ?int $depotId): TripLink
    {
        $link->update(['depot_id' => $depotId]);

        Log::info('Trip link depot set', [
            'trip_link_id' => $link->id,
            'kind' => $link->kind->value,
            'depot_id' => $depotId,
        ]);

        return $link->refresh();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeDepot(TripLink $link): ?array
    {
        if ($link->depot_id === null) {
            return null;
        }

        $hof = $link->relationLoaded('depot') ? $link->depot : Depot::query()->find($link->depot_id);

        return $hof === null ? null : [
            'id' => $hof->id,
            'name' => $hof->name,
            'display' => $hof->display(),
            'active' => $hof->active,
        ];
    }

    public function remove(TripLink $link): void
    {
        $id = $link->id;
        $kind = $link->kind->value;
        $from = $link->from_trip_id;
        $to = $link->to_trip_id;
        $depot = $link->depot_id;

        $link->delete();

        Log::info('Trip link removed', [
            'trip_link_id' => $id,
            'kind' => $kind,
            'from_trip_id' => $from,
            'to_trip_id' => $to,
            'depot_id' => $depot,
        ]);
    }

    /**
     * Wendezeit in Sekunden, gerechnet **entlang des Betriebstags**.
     *
     * Nicht nach der Uhr: Auf der N1 folgt auf eine Ankunft um 23:20 eine Abfahrt um 00:19 —
     * als Uhrzeit ein Rücksprung, auf dem Betriebstag aber 59 Minuten Wende. Ohne diese
     * Rechnung wäre jeder Nachtlinien-Anschluss über Mitternacht als negative Wendezeit
     * abgewiesen worden.
     *
     * Jede Seite trägt die Grenze **ihrer eigenen Linie** (OperatingDayResolver): Beim
     * Übergang von einer Tag- auf eine Nachtlinie gelten verschiedene Grenzen, und genau das
     * ist richtig.
     *
     * `null`, wenn eine der beiden Zeiten fehlt oder unlesbar ist.
     */
    public function turnaroundSeconds(ConsolidatedTrip $from, ConsolidatedTrip $to): ?int
    {
        $info = $this->tripInfo->forIds([$from->id, $to->id]);

        return $this->turnaroundFrom($info[$from->id] ?? null, $info[$to->id] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $von
     * @param  array<string, mixed>|null  $nach
     */
    private function turnaroundFrom(?array $von, ?array $nach): ?int
    {
        if ($von === null || $nach === null) {
            return null;
        }

        $ankunft = $von['arrival_sort'];
        $abfahrt = $nach['departure_sort'];

        // Unlesbare Zeiten sortieren ans Ende; daraus eine Wendezeit zu rechnen wäre erfunden.
        if ($ankunft === PHP_INT_MAX || $abfahrt === PHP_INT_MAX) {
            return null;
        }

        return $abfahrt - $ankunft;
    }

    /**
     * Alle Fahrten, die mit dieser über Anschlüsse zusammenhängen — sie selbst eingeschlossen.
     *
     * **Eine Menge, keine Folge.** Solange jede Fahrt höchstens einen Nachfolger hatte, war die
     * Kette eine Kette. Seit sie je Tag einen anderen haben darf (KURSE §3), verzweigt sie
     * sich: Über den Versionswechsel einer Nachbarlinie hinweg gehören beide Zweige demselben
     * Fahrzeug, aber nie demselben Tag.
     *
     * Und beide gehören zum selben Kurs — die Nummer ist das Etikett am Fahrzeug, nicht am
     * einzelnen Fahrplanstand (K2). Wer eine Reihenfolge oder einen Stand braucht, gibt einen
     * Zeitraum vor.
     *
     * Früher wanderte diese Abfrage je Kettensprung einzeln durch die Datenbank und folgte
     * dabei einem beliebigen der Nachfolger — bei mehreren fand sie je nach Zeilenreihenfolge
     * eine andere Kette. Jetzt baut sie denselben Graphen auf, den auch die Mengen-Läufe nutzen.
     *
     * @param  array<int, DayRange>|null  $zeitraum  nur Anschlüsse, die ihn berühren
     * @return array<int, int> `consolidated_trips.id`, aufsteigend
     */
    public function chainFor(ConsolidatedTrip $trip, ?array $zeitraum = null): array
    {
        return $this->graphFor([$trip->id])->chainOf($trip->id, $zeitraum);
    }

    /**
     * Die Ketten rund um eine Menge von Fahrten — **einmal geladen**, statt je Sprung gefragt.
     *
     * Für einen Einzelklick ist {@see chainFor()} richtig: zwei, drei Abfragen, immer aktuell.
     * Ein Mengen-Lauf über 60 Ankünfte oder 120 Fahrplanspalten löst damit tausende aus, und —
     * schwerer wiegend — er sieht seine **eigenen, erst geplanten** Paare nicht. Der Graph nimmt
     * sie auf ({@see TripChainGraph::link()}) und prüft Ringe und Belegung dagegen mit.
     *
     * Ausgehend von der Saat wird über die gefundenen Partner expandiert, bis nichts Neues mehr
     * dazukommt: Eine Kette reicht über die Saat hinaus, und eine halbe Kette beantwortete die
     * Frage „gehört diese Fahrt schon zu einem Umlauf?" falsch.
     *
     * @param  array<int, int>  $seedTripIds
     */
    public function graphFor(array $seedTripIds): TripChainGraph
    {
        $offen = array_values(array_unique(array_filter($seedTripIds)));
        $bekannt = [];
        $zeilen = [];
        $gesehene = [];

        while ($offen !== []) {
            $gefunden = [];

            foreach (array_chunk($offen, 500) as $teil) {
                $treffer = DB::table('trip_links')
                    ->where(function ($q) use ($teil): void {
                        $q->whereIn('from_trip_id', $teil)->orWhereIn('to_trip_id', $teil);
                    })
                    ->get(['id', 'from_trip_id', 'to_trip_id']);

                foreach ($treffer as $zeile) {
                    // Eine Zeile wird über beide Enden gefunden — ohne dieses Set stünde sie
                    // zweimal im Graphen und die Expansion liefe im Kreis.
                    if (isset($gesehene[$zeile->id])) {
                        continue;
                    }

                    $gesehene[$zeile->id] = true;
                    // Die Tage der Kante: Ohne sie könnte der Graph zwei Anschlüsse derselben
                    // Fahrt nicht auseinanderhalten, und die Zyklusprüfung wiese einen Ring ab,
                    // dessen Kanten einander nie begegnen.
                    $zeile->days = $this->validity->forLink(
                        $zeile->from_trip_id === null ? null : (int) $zeile->from_trip_id,
                        $zeile->to_trip_id === null ? null : (int) $zeile->to_trip_id,
                    );
                    $zeilen[] = $zeile;

                    foreach ([$zeile->from_trip_id, $zeile->to_trip_id] as $partner) {
                        if ($partner !== null) {
                            $gefunden[(int) $partner] = true;
                        }
                    }
                }
            }

            foreach ($offen as $id) {
                $bekannt[$id] = true;
            }

            $offen = array_values(array_diff(array_keys($gefunden), array_keys($bekannt)));
        }

        return TripChainGraph::fromRows($zeilen);
    }

    /**
     * Würde ein Anschluss `$fromId → $toId` einen Ring schließen?
     *
     * Ein Umlauf ist eine Folge, kein Kreis — ein Fahrzeug fährt einen Betriebstag von vorn
     * nach hinten durch. Ohne diese Prüfung wäre die Kette nicht mehr auslesbar.
     */
    public function wouldCreateCycle(int $fromId, int $toId, array $tage = []): bool
    {
        return $this->graphFor([$fromId, $toId])->wouldCreateCycle($fromId, $toId, $tage);
    }
}
