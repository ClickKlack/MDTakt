<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RouteType;
use App\Enums\TripLinkRejection;
use App\Http\Requests\TripLinkRequest;
use App\Models\ConsolidatedTrip;
use App\Models\LineVersionInterval;
use App\Support\TripChainGraph;
use App\Support\TripLinkRejectionReason;

/**
 * Ob ein Anschluss zulässig ist (KURSE §4) — **einmal**, für beide Aufrufer.
 *
 * Die Regeln standen ursprünglich im {@see TripLinkRequest}, weil dort aus
 * einem Verstoß der einheitliche 422-Envelope wird. Der Mengen-Lauf braucht dieselben Regeln,
 * darf aber nicht abbrechen: Er überspringt eine Zeile und läuft weiter. Gedoppelt liefen die
 * beiden Fassungen auseinander, und dann verknüpfte der Lauf etwas, das der Einzelklick abweist.
 *
 * Deshalb liegen sie hier und der Dienst **wirft nie** — er antwortet mit einem Grund oder mit
 * `null`. Der Request macht daraus einen Validierungsfehler, der Lauf eine Zeile in `skipped`.
 *
 * Nicht geprüft wird der **Linienwechsel**: Eine 1 wird in Sudenburg zur 13, das ist der
 * Normalfall einer Fahrzeugkette (K1). Er erscheint als Hinweis, nicht als Fehler.
 *
 * Der **Gattungswechsel** dagegen wird abgewiesen: Ein Fahrzeug wird nie vom Tram zum Bus. Beides
 * auseinanderzuhalten ist wesentlich, weil dieselbe Linie beides sein kann — N2 liegt zeitweise
 * als Tram und als Bus vor (Schienenersatzverkehr).
 */
final class TripLinkRuleService
{
    /**
     * @var array<int, int|null> consolidated_stop_id => stop_group_id
     *
     * Kurzlebiger Merkzettel für die Dauer **einer** Anfrage. Bewusst hier und nicht im
     * {@see StopGroupService}: Der ordnet Halte auch um (`assign`, `merge`, `detach`), und ein
     * Merkzettel dort wäre nach der ersten Umordnung falsch. Hier kann er das nicht werden —
     * während einer Zulässigkeitsprüfung verschiebt niemand Haltestellen.
     */
    private array $gruppeJeHalt = [];

    /** @var array<string, bool> "kleinereId|groessereId" => überschneiden sich */
    private array $gueltigkeitCache = [];

    public function __construct(
        private readonly StopGroupService $groups,
        private readonly TripLinkService $links,
    ) {}

    /**
     * Wärmt den Halt→Haltestelle-Merkzettel für einen ganzen Lauf vor.
     *
     * Ohne das ist `groupIdFor()` eine Abfrage je Halt, und die Prüfung fragt zwei je
     * Kandidatenpaar. Bei 60 Ankünften mit je mehreren Kandidaten sind das tausende.
     *
     * @param  array<int, int>  $stopIds
     */
    public function warmStopGroups(array $stopIds): void
    {
        $offen = array_values(array_diff(
            array_unique(array_filter($stopIds)),
            array_keys($this->gruppeJeHalt),
        ));

        if ($offen === []) {
            return;
        }

        $gefunden = $this->groups->groupIdsFor($offen);

        foreach ($offen as $stopId) {
            // Auch das Nichtvorhandensein wird gemerkt — sonst fragt jeder Durchlauf erneut
            // nach genau den Halten, die zu keiner Haltestelle gehören.
            $this->gruppeJeHalt[$stopId] = $gefunden[$stopId] ?? null;
        }
    }

    /**
     * Der Grund, aus dem dieser Anschluss nicht zulässig ist — oder `null`, wenn er es ist.
     *
     * `$graph` ist der Kettenstand **einschließlich der bereits geplanten Kanten** eines Laufs.
     * Ohne ihn wird wie bisher gegen die Datenbank geprüft; der Einzelklick verhält sich damit
     * unverändert.
     */
    public function rejectionFor(
        ConsolidatedTrip $from,
        ConsolidatedTrip $to,
        ?TripChainGraph $graph = null,
    ): ?TripLinkRejectionReason {
        if ($from->id === $to->id) {
            return $this->grund(
                TripLinkRejection::SameTrip,
                'Eine Fahrt kann nicht an sich selbst anschließen.',
            );
        }

        $vonVersion = $from->lineVersion;
        $nachVersion = $to->lineVersion;

        if ($vonVersion->period_id !== $nachVersion->period_id) {
            return $this->grund(
                TripLinkRejection::DifferentPeriod,
                'Die Fahrten gehören zu verschiedenen Fahrplanperioden.',
            );
        }

        if ($vonVersion->day_type !== $nachVersion->day_type) {
            return $this->grund(
                TripLinkRejection::DifferentDayType,
                'Die Fahrten gehören zu verschiedenen Fahrplantypen.',
            );
        }

        // Ein Fahrzeug wechselt die Gattung nicht. Das Verkehrsmittel hängt an der Fahrt, nicht
        // an der Linie: N2 liegt zeitweise als Tram und als Bus vor, und genau dort träfe die
        // Verwechslung zu.
        $vonMittel = RouteType::modeFor($from->route_type);
        $nachMittel = RouteType::modeFor($to->route_type);

        if ($vonMittel !== $nachMittel) {
            return $this->grund(
                TripLinkRejection::ModeChange,
                sprintf(
                    'Ein Fahrzeug wechselt die Gattung nicht: Die erste Fahrt ist %s, die zweite %s.',
                    $this->mittelName($vonMittel),
                    $this->mittelName($nachMittel),
                ),
            );
        }

        // Geprüft wird die **Haltestelle**, nicht der einzelne Halt. An einer Endstelle liegen
        // Ankunft und Abfahrt oft auf verschiedenen Punkten: An „Herrenkrug" enden Fahrten auf
        // dem einen Bahnsteig und beginnen 72 m weiter auf dem anderen. Netzweit sind 64 von
        // 104 Endstellen so gebaut — auf Halt-Identität zu prüfen hieße, an über der Hälfte
        // aller Fahrt-Endpunkte keinen Anschluss zulassen zu können (KURSE §3.1).
        $ende = $from->last_stop_id === null ? null : $this->groupIdFor($from->last_stop_id);
        $anfang = $to->first_stop_id === null ? null : $this->groupIdFor($to->first_stop_id);

        if ($ende === null || $anfang === null || $ende !== $anfang) {
            return $this->grund(
                TripLinkRejection::StopGroupMismatch,
                'Die zweite Fahrt beginnt nicht an der Haltestelle, an der die erste endet. '
                .'Gehören die beiden Bahnsteige zusammen, sind sie unter „Haltestellen" derselben Haltestelle zuzuordnen.',
            );
        }

        // Überschneiden sich die Gültigkeiten an keinem Tag, könnte der Anschluss nie zustande
        // kommen — die beiden Fahrpläne standen nie gleichzeitig in Kraft.
        if (! $this->gueltigkeitenUeberschneidenSich($vonVersion->id, $nachVersion->id)) {
            return $this->grund(
                TripLinkRejection::NoCommonValidity,
                'Die Fahrplan-Versionen der beiden Fahrten gelten an keinem gemeinsamen Tag.',
            );
        }

        $ring = $graph === null
            ? $this->links->wouldCreateCycle($from->id, $to->id)
            : $graph->wouldCreateCycle($from->id, $to->id);

        if ($ring) {
            return $this->grund(
                TripLinkRejection::Cycle,
                'Dieser Anschluss würde die Kette zu einem Ring schließen.',
            );
        }

        $wendezeit = $this->links->turnaroundSeconds($from, $to);

        if ($wendezeit !== null && $wendezeit < 0) {
            return $this->grund(
                TripLinkRejection::NegativeTurnaround,
                'Die zweite Fahrt beginnt vor dem Ende der ersten — das ergibt eine negative Wendezeit.',
            );
        }

        return null;
    }

    private function grund(TripLinkRejection $code, string $message): TripLinkRejectionReason
    {
        return new TripLinkRejectionReason($code, $message);
    }

    private function groupIdFor(int $stopId): ?int
    {
        if (! array_key_exists($stopId, $this->gruppeJeHalt)) {
            $this->gruppeJeHalt[$stopId] = $this->groups->groupIdFor($stopId);
        }

        return $this->gruppeJeHalt[$stopId];
    }

    private function mittelName(string $mode): string
    {
        return match ($mode) {
            'tram' => 'eine Tram',
            'bus' => 'ein Bus',
            default => 'ein anderes Verkehrsmittel',
        };
    }

    /**
     * Überschneiden sich die beobachteten Gültigkeiten zweier Versionen an mindestens einem Tag?
     *
     * Bewusst in PHP statt als SQL-Join: Die `date`-Spalten tragen unter SQLite eine Uhrzeit,
     * unter PostgreSQL nicht (siehe CLAUDE.md). Ein Spaltenvergleich in SQL wäre damit von der
     * Schreibweise abhängig. Je Version stehen ohnehin nur wenige Intervalle an.
     *
     * Gemerkt wird das Ergebnis je Versionspaar: In einem Lauf stehen immer wieder dieselben
     * beiden Versionen gegeneinander, und jede Prüfung lüde sonst dieselben Intervalle erneut.
     */
    private function gueltigkeitenUeberschneidenSich(int $vonVersionId, int $nachVersionId): bool
    {
        $schluessel = min($vonVersionId, $nachVersionId).'|'.max($vonVersionId, $nachVersionId);

        if (isset($this->gueltigkeitCache[$schluessel])) {
            return $this->gueltigkeitCache[$schluessel];
        }

        $intervalle = LineVersionInterval::query()
            ->whereIn('line_version_id', [$vonVersionId, $nachVersionId])
            ->get()
            ->groupBy('line_version_id');

        $a = $intervalle->get($vonVersionId, collect());
        $b = $intervalle->get($nachVersionId, collect());

        foreach ($a as $links) {
            foreach ($b as $rechts) {
                if ($links->valid_from <= $rechts->valid_to && $rechts->valid_from <= $links->valid_to) {
                    return $this->gueltigkeitCache[$schluessel] = true;
                }
            }
        }

        return $this->gueltigkeitCache[$schluessel] = false;
    }
}
