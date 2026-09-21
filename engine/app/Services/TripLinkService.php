<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TripLinkKind;
use App\Http\Requests\TripLinkRequest;
use App\Models\ConsolidatedTrip;
use App\Models\TripLink;
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
    ): TripLink {
        $stopId = $kind === TripLinkKind::Start
            ? $to?->first_stop_id
            : $from?->last_stop_id;

        $link = TripLink::query()->create([
            'from_trip_id' => $from?->id,
            'to_trip_id' => $to?->id,
            'stop_id' => $stopId,
            'kind' => $kind,
            'note' => $note,
        ]);

        Log::info('Trip link created', [
            'trip_link_id' => $link->id,
            'kind' => $kind->value,
            'from_trip_id' => $from?->id,
            'to_trip_id' => $to?->id,
            'stop_id' => $stopId,
        ]);

        return $link;
    }

    public function remove(TripLink $link): void
    {
        $id = $link->id;
        $kind = $link->kind->value;
        $from = $link->from_trip_id;
        $to = $link->to_trip_id;

        $link->delete();

        Log::info('Trip link removed', [
            'trip_link_id' => $id,
            'kind' => $kind,
            'from_trip_id' => $from,
            'to_trip_id' => $to,
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
     * Alle Fahrten der Kette, zu der diese Fahrt gehört — Vorgänger zuerst.
     *
     * @return array<int, int> `consolidated_trips.id` in Fahrreihenfolge
     */
    public function chainFor(ConsolidatedTrip $trip): array
    {
        $rueckwaerts = [];
        $aktuell = $trip->id;
        $gesehen = [$trip->id => true];

        // Die Unique-Constraints schließen Mehrfachkanten aus, `wouldCreateCycle()` die
        // Ringe. Das Gesehen-Set ist trotzdem da: Ein Zyklus aus einem Altbestand darf
        // die Abfrage hängen lassen können.
        while (($vorher = $this->predecessorOf($aktuell)) !== null && ! isset($gesehen[$vorher])) {
            array_unshift($rueckwaerts, $vorher);
            $gesehen[$vorher] = true;
            $aktuell = $vorher;
        }

        $vorwaerts = [];
        $aktuell = $trip->id;

        while (($danach = $this->successorOf($aktuell)) !== null && ! isset($gesehen[$danach])) {
            $vorwaerts[] = $danach;
            $gesehen[$danach] = true;
            $aktuell = $danach;
        }

        return [...$rueckwaerts, $trip->id, ...$vorwaerts];
    }

    /**
     * Würde ein Anschluss `$fromId → $toId` einen Ring schließen?
     *
     * Ein Umlauf ist eine Folge, kein Kreis — ein Fahrzeug fährt einen Betriebstag von vorn
     * nach hinten durch. Ohne diese Prüfung wäre die Kette nicht mehr auslesbar.
     */
    public function wouldCreateCycle(int $fromId, int $toId): bool
    {
        if ($fromId === $toId) {
            return true;
        }

        $aktuell = $toId;
        $gesehen = [$toId => true];

        while (($danach = $this->successorOf($aktuell)) !== null) {
            if ($danach === $fromId) {
                return true;
            }

            if (isset($gesehen[$danach])) {
                return true;
            }

            $gesehen[$danach] = true;
            $aktuell = $danach;
        }

        return false;
    }

    private function successorOf(int $tripId): ?int
    {
        $wert = DB::table('trip_links')
            ->where('from_trip_id', $tripId)
            ->whereNotNull('to_trip_id')
            ->value('to_trip_id');

        return $wert === null ? null : (int) $wert;
    }

    private function predecessorOf(int $tripId): ?int
    {
        $wert = DB::table('trip_links')
            ->where('to_trip_id', $tripId)
            ->whereNotNull('from_trip_id')
            ->value('from_trip_id');

        return $wert === null ? null : (int) $wert;
    }
}
