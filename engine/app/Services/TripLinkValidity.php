<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Support\DayRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * **An welchen Tagen gilt ein Anschluss** — und welcher bestehende käme ihm dabei in die Quere.
 *
 * Bis zum 23.09.2026 trug `trip_links` zwei Unique-Constraints und damit die Fachregel „ein
 * Fahrzeug hat höchstens einen Nachfolger". Das stimmt an *einem Tag*, aber eine Fahrt lebt über
 * viele Tage, und die fallen in verschiedene Versionsstände: Wechselt eine Nachbarlinie mitten
 * in der Periode die Version, braucht dieselbe Fahrt ab dem Wechseltag einen **anderen**
 * Nachfolger. Der Constraint machte das unmöglich (KURSE §2 K7).
 *
 * An seine Stelle tritt diese Prüfung. Die Regel gilt unverändert — nur je Tag statt je Fahrt:
 * Zwei Anschlüsse an derselben Fahrt sind erlaubt, solange sie sich an keinem Tag berühren.
 *
 * **Gerechnet wird in Betriebstagen.** `line_version_intervals` steht bereits darin: Eine
 * N1-Fahrt um 01:45 läuft kalendarisch am Samstag, ihr Intervall nennt den Freitag, weil die
 * Betriebstag-Grenze der Nachtlinien bei 12:00 liegt ({@see OperatingDayResolver}). Für einen
 * Anschluss vom Tag- aufs Nachtnetz ist das wesentlich: Beide Seiten tragen denselben
 * Betriebstag, und nur deshalb ist ihr Schnitt überhaupt aussagekräftig. Kalendarisch gerechnet
 * läge jeder solche Anschluss um einen Tag daneben.
 */
final class TripLinkValidity
{
    /** @var array<int, array<int, DayRange>> line_version_id => Tage, an denen sie wirklich gilt */
    private array $intervalle = [];

    /** @var array<int, int|null> consolidated_trip_id => line_version_id */
    private array $versionJeFahrt = [];

    /** @var array<string, FahrplanTyp> Datum => Typ, damit der Klassifizierer nicht je Tag erneut abfragt */
    private array $typJeTag = [];

    public function __construct(private readonly FahrplanTypClassifier $classifier) {}

    /**
     * Die Tage, an denen dieser Anschluss gilt.
     *
     * Bei einem Anschluss der Schnitt beider Linien-Versionen — das Fahrzeug kann nur weiterfahren,
     * wenn beide Fahrten an diesem Tag stattfinden. Bei einer Betriebsfahrt (`start`/`end`) die
     * Tage der einen beteiligten Fahrt.
     *
     * @return array<int, DayRange> leer, wenn sich die beiden an keinem Tag begegnen
     */
    public function forLink(?int $fromTripId, ?int $toTripId): array
    {
        $von = $fromTripId === null ? null : $this->forTrip($fromTripId);
        $nach = $toTripId === null ? null : $this->forTrip($toTripId);

        if ($von === null) {
            return $nach ?? [];
        }

        if ($nach === null) {
            return $von;
        }

        return DayRange::intersect($von, $nach);
    }

    /**
     * Der bestehende Anschluss, der einem neuen an derselben Fahrt in die Quere käme — oder
     * `null`, wenn keiner das tut.
     *
     * Geprüft werden **beide Seiten**: Die endende Fahrt darf an einem Tag nur einen Nachfolger
     * haben, die beginnende nur einen Vorgänger. Eine `end`-Marke belegt dabei die Nachfolger-
     * Seite ebenso wie ein Anschluss — das Fahrzeug fährt danach in den Betriebshof und nicht
     * zugleich weiter.
     */
    public function conflictFor(?int $fromTripId, ?int $toTripId, ?int $ignoreLinkId = null): ?object
    {
        $neu = $this->forLink($fromTripId, $toTripId);

        if ($neu === []) {
            return null;
        }

        foreach ($this->existing($fromTripId, $toTripId, $ignoreLinkId) as $zeile) {
            $bestehend = $this->forLink(
                $zeile->from_trip_id === null ? null : (int) $zeile->from_trip_id,
                $zeile->to_trip_id === null ? null : (int) $zeile->to_trip_id,
            );

            if (DayRange::overlap($neu, $bestehend)) {
                return $zeile;
            }
        }

        return null;
    }

    /**
     * Gilt dieser Anschluss an mindestens einem Tag dieses Zeitraums?
     *
     * Damit entscheidet der Haltestellen-Editor, welche Entscheidung er in einem Versionsstand
     * zeigt: Eine Fahrt kann mehrere tragen, aber in einem Stand gilt höchstens eine.
     *
     * @param  array<int, DayRange>  $zeitraum
     */
    public function appliesIn(?int $fromTripId, ?int $toTripId, array $zeitraum): bool
    {
        return DayRange::overlap($this->forLink($fromTripId, $toTripId), $zeitraum);
    }

    /**
     * Die Tage einer Fahrt — die ihrer Linien-Version.
     *
     * @return array<int, DayRange>|null `null`, wenn die Fahrt unbekannt ist
     */
    public function forTrip(int $tripId): ?array
    {
        $versionId = $this->versionOf($tripId);

        return $versionId === null ? null : $this->forVersion($versionId);
    }

    /**
     * Die Tage, an denen diese Version gilt — **beschnitten auf ihren Fahrplantyp**.
     *
     * `line_version_intervals` trägt Spannen, keine Tagesmengen: Eine `mo_fr`-Version vom 21.09.
     * bis 15.10. schließt vier Wochenenden ein, an denen sie nicht gilt. Ohne den Schnitt
     * meldete die Deckungsrechnung ausgerechnet dort eine Lücke, wo die Pflege vollständig ist:
     * Zwei aufeinanderfolgende Versionen einer Nachbarlinie grenzen am Freitag und am Montag
     * aneinander, und der Samstag dazwischen sähe aus wie ein Tag ohne Nachfolger.
     *
     * Dieselbe Beschneidung wie beim Versionsstand ({@see StopLinkBoardService::trimToType()}),
     * und aus demselben Grund — ein Datum, an dem nichts gilt, hat in keiner der beiden
     * Rechnungen etwas zu suchen.
     *
     * @return array<int, DayRange>
     */
    public function forVersion(int $versionId): array
    {
        if (isset($this->intervalle[$versionId])) {
            return $this->intervalle[$versionId];
        }

        $version = DB::table('line_versions')->where('id', $versionId)->first(['day_type']);

        if ($version === null) {
            return $this->intervalle[$versionId] = [];
        }

        $typ = FahrplanTyp::from((string) $version->day_type);
        $tage = [];

        foreach (DB::table('line_version_intervals')->where('line_version_id', $versionId)->get(['valid_from', 'valid_to']) as $zeile) {
            $von = CarbonImmutable::parse(substr((string) $zeile->valid_from, 0, 10));
            $bis = CarbonImmutable::parse(substr((string) $zeile->valid_to, 0, 10));

            for ($tag = $von; $tag <= $bis; $tag = $tag->addDay()) {
                $schluessel = $tag->toDateString();

                $this->typJeTag[$schluessel] ??= $this->classifier->classify($tag);

                if ($this->typJeTag[$schluessel] === $typ) {
                    $tage[$schluessel] = true;
                }
            }
        }

        return $this->intervalle[$versionId] = DayRange::fromDays(array_keys($tage));
    }

    /**
     * Die Tage eines Versionsstands, aus seinen eigenen Zeiträumen.
     *
     * **Nicht** die Vereinigung der Gültigkeiten seiner Versionen: Ein Stand ist der Abschnitt,
     * in dem genau diese Versionsmenge gilt, und seine Versionen gelten darüber hinaus auch an
     * anderen Tagen. Am City Carré umfasst der Stand 12.10.–15.10. vier Versionen, von denen
     * drei die ganze Periode über laufen — ihre Vereinigung wäre der ganze Zeitraum und träfe
     * jeden Anschluss.
     *
     * @param  array<int, array{valid_from: string, valid_to: string}>  $ranges
     * @return array<int, DayRange>
     */
    public function forRanges(array $ranges): array
    {
        return array_values(array_map(
            static fn (array $r): DayRange => DayRange::fromStrings($r['valid_from'], $r['valid_to']),
            $ranges,
        ));
    }

    /**
     * Bestehende Zeilen, die dieselbe Seite derselben Fahrt belegen.
     *
     * @return array<int, object>
     */
    private function existing(?int $fromTripId, ?int $toTripId, ?int $ignoreLinkId): array
    {
        if ($fromTripId === null && $toTripId === null) {
            return [];
        }

        return DB::table('trip_links')
            ->where(function ($q) use ($fromTripId, $toTripId): void {
                if ($fromTripId !== null) {
                    $q->orWhere('from_trip_id', $fromTripId);
                }
                if ($toTripId !== null) {
                    $q->orWhere('to_trip_id', $toTripId);
                }
            })
            ->when($ignoreLinkId !== null, static fn ($q) => $q->where('id', '!=', $ignoreLinkId))
            ->get(['id', 'from_trip_id', 'to_trip_id', 'kind'])
            ->all();
    }

    private function versionOf(int $tripId): ?int
    {
        if (array_key_exists($tripId, $this->versionJeFahrt)) {
            return $this->versionJeFahrt[$tripId];
        }

        $wert = DB::table('consolidated_trips')->where('id', $tripId)->value('line_version_id');

        return $this->versionJeFahrt[$tripId] = $wert === null ? null : (int) $wert;
    }
}
