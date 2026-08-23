<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ConsolidatedStop;
use App\Models\ConsolidatedStopVersion;
use App\Models\Stop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Führt die Roh-Halte eines Imports in den dauerhaften Halte-Bestand über
 * (FAHRPLANPERIODEN §5.1, §6).
 *
 * **Warum überhaupt:** gtfs.de vergibt `stop_id`s pro Build; ein Halt, der nach dem nächsten
 * Import eine neue ID trägt, wäre ohne Dedup ein neuer Halt — und alles, was daran hängt,
 * verlöre seinen Bezug. Die Identität liegt deshalb in **Koordinaten + normalisiertem Namen**.
 *
 * **Regel (entschieden 21.08.2026):** Zwei Halte sind derselbe, wenn sie höchstens
 * `MERGE_DISTANCE_METERS` auseinanderliegen **und** ihr normalisierter Name übereinstimmt.
 * Am Live-Bestand gemessen verschmelzen bis 15 m ausschließlich namensgleiche Halte; die
 * erste echte Fehlverschmelzung liegt bei 17,1 m. 12 m hält Sicherheitsabstand.
 *
 * **Steige verschmelzen dabei bewusst:** 18 Halte des MVB-Netzes liegen auf exakt identischen
 * Koordinaten und tragen dort die beiden Fahrtrichtungen. Keine Schwelle kann die trennen —
 * die Richtung kommt aus der Position in der Fahrt-Sequenz, nicht aus dem Halt.
 */
final class StopConsolidationService
{
    /** Dedup-Schwelle in Metern (FAHRPLANPERIODEN §5.1, entschieden 21.08.2026). */
    private const MERGE_DISTANCE_METERS = 12.0;

    private const EARTH_RADIUS_METERS = 6371000.0;

    public function __construct(private readonly StopNameNormalizer $normalizer) {}

    /**
     * Ordnet jeden Roh-Halt einer dauerhaften Identität zu und schreibt die Attribut-Historie
     * für das beobachtete Fenster fort.
     *
     * @return array<string, int> Roh-`stop_id` => `consolidated_stops.id`
     */
    public function consolidate(CarbonImmutable $windowFrom, CarbonImmutable $windowTo): array
    {
        $jetzt = CarbonImmutable::now();
        $zuordnung = [];
        $neue = 0;
        $kandidaten = [];

        // Nach Name gruppiert laden: Die Kandidatensuche läuft ohnehin je Namensschlüssel,
        // und so bleibt der Speicherbedarf bei 730 Halten trivial.
        foreach (Stop::query()->orderBy('stop_id')->get() as $roh) {
            if ($roh->lat === null || $roh->lon === null) {
                Log::warning('Stop without coordinates skipped during consolidation', [
                    'stop_id' => $roh->stop_id,
                    'stop_name' => $roh->stop_name,
                ]);

                continue;
            }

            $nameKey = $this->normalizer->normalize($roh->stop_name);
            $lat = (float) $roh->lat;
            $lon = (float) $roh->lon;

            $halt = $this->findIdentity($nameKey, $lat, $lon);

            if ($halt === null) {
                $halt = ConsolidatedStop::query()->create([
                    'anchor_lat' => $this->coordinate($lat),
                    'anchor_lon' => $this->coordinate($lon),
                    'name_key' => $nameKey,
                    'first_seen_at' => $jetzt,
                    'last_seen_at' => $jetzt,
                ]);
                $neue++;
            } else {
                $halt->update(['last_seen_at' => $jetzt]);
            }

            // Mehrere Roh-Halte fallen auf dieselbe Identität — im MVB-Netz sind das gut 110
            // von 730, überwiegend Steige und Schreibvarianten. Welcher davon Name und Lage
            // bestimmt, wird erst im zweiten Durchgang entschieden: Der Sieger muss über
            // Builds hinweg derselbe sein, sonst erfindet jeder Import eine Verlegung.
            $kandidaten[$halt->id][] = ['name' => $roh->stop_name, 'lat' => $lat, 'lon' => $lon];
            $zuordnung[$roh->stop_id] = $halt->id;
        }

        foreach ($kandidaten as $haltId => $rohHalte) {
            $halt = ConsolidatedStop::query()->find($haltId);

            if ($halt === null) {
                continue;
            }

            $vertreter = $this->representative($halt, $rohHalte);
            $this->recordAttributes(
                $halt, $vertreter['name'], $vertreter['lat'], $vertreter['lon'], $windowFrom, $windowTo
            );
        }

        Log::info('Stops consolidated', [
            'raw_stops' => count($zuordnung),
            'consolidated_stops' => ConsolidatedStop::query()->count(),
            'new_identities' => $neue,
        ]);

        return $zuordnung;
    }

    /**
     * Wählt den Roh-Halt, der Name und Lage der Identität bestimmt: den **ankernächsten**.
     *
     * Naheliegend wäre „der zuerst gesehene", aber die Reihenfolge hängt an der `stop_id` —
     * und die vergibt gtfs.de pro Build vollständig neu (gemessen 23.08.2026: **null** von 730
     * IDs überlebte den Folge-Build). Bei verschmolzenen Steigen gewänne dann mal der eine,
     * mal der andere Bahnsteig, und die Attribut-Historie füllte sich mit Verlegungen um
     * wenige Meter, die nie stattgefunden haben — im ersten Folge-Import waren das 53 Stück.
     *
     * Der Anker liegt seit der ersten Sichtung fest. Damit gewinnt immer derselbe physische
     * Bahnsteig, egal wie der Feed seine IDs würfelt. Bei exakter Gleichheit entscheidet der
     * Name, damit auch das reproduzierbar bleibt.
     *
     * @param  array<int, array{name: string, lat: float, lon: float}>  $rohHalte
     * @return array{name: string, lat: float, lon: float}
     */
    private function representative(ConsolidatedStop $halt, array $rohHalte): array
    {
        $ankerLat = (float) $halt->anchor_lat;
        $ankerLon = (float) $halt->anchor_lon;

        usort($rohHalte, function (array $a, array $b) use ($ankerLat, $ankerLon): int {
            $distanzA = $this->distance($ankerLat, $ankerLon, $a['lat'], $a['lon']);
            $distanzB = $this->distance($ankerLat, $ankerLon, $b['lat'], $b['lon']);

            return $distanzA <=> $distanzB ?: strcmp($a['name'], $b['name']);
        });

        return $rohHalte[0];
    }

    /**
     * Sucht die bestehende Identität: gleicher Namensschlüssel und höchstens
     * MERGE_DISTANCE_METERS entfernt. Vorauswahl per Bounding-Box, danach die genaue
     * Distanz — eine Box allein wäre an den Ecken zu großzügig.
     */
    private function findIdentity(string $nameKey, float $lat, float $lon): ?ConsolidatedStop
    {
        $latDelta = self::MERGE_DISTANCE_METERS / 111320.0;
        $lonDelta = $latDelta / max(cos(deg2rad($lat)), 0.01);

        $kandidaten = ConsolidatedStop::query()
            ->where('name_key', $nameKey)
            ->whereBetween('anchor_lat', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('anchor_lon', [$lon - $lonDelta, $lon + $lonDelta])
            ->get();

        $beste = null;
        $besteDistanz = self::MERGE_DISTANCE_METERS;

        foreach ($kandidaten as $kandidat) {
            $distanz = $this->distance($lat, $lon, (float) $kandidat->anchor_lat, (float) $kandidat->anchor_lon);

            if ($distanz <= $besteDistanz) {
                $beste = $kandidat;
                $besteDistanz = $distanz;
            }
        }

        return $beste;
    }

    /**
     * Schreibt Name und Lage für das beobachtete Fenster fort.
     *
     * Sind die Attribute unverändert, wächst der Gültigkeitszeitraum der bestehenden Version.
     * Haben sie sich geändert, endet die alte Version und eine neue beginnt — der genaue Tag
     * des Wechsels ist unbekannt (`stops.txt` trägt kein Datum), aber **dass** gewechselt
     * wurde, ist beobachtet. Deshalb `to_confirmed`/`from_confirmed` an der Nahtstelle.
     */
    private function recordAttributes(
        ConsolidatedStop $halt,
        string $name,
        float $lat,
        float $lon,
        CarbonImmutable $windowFrom,
        CarbonImmutable $windowTo,
    ): void {
        $aktuell = $halt->versions()->orderByDesc('valid_to')->first();
        $unveraendert = $aktuell !== null
            && $aktuell->name === $name
            && $this->coordinate((float) $aktuell->lat) === $this->coordinate($lat)
            && $this->coordinate((float) $aktuell->lon) === $this->coordinate($lon);

        if ($unveraendert) {
            $von = CarbonImmutable::parse($aktuell->valid_from->toDateString());
            $bis = CarbonImmutable::parse($aktuell->valid_to->toDateString());

            $aktuell->update([
                'valid_from' => $von->lessThan($windowFrom) ? $von->toDateString() : $windowFrom->toDateString(),
                'valid_to' => $bis->greaterThan($windowTo) ? $bis->toDateString() : $windowTo->toDateString(),
            ]);

            return;
        }

        if ($aktuell !== null) {
            // Die alte Version endet, wo die neue Beobachtung beginnt.
            $aktuell->update([
                'valid_to' => $windowFrom->subDay()->toDateString(),
                'to_confirmed' => true,
            ]);
        }

        ConsolidatedStopVersion::query()->create([
            'consolidated_stop_id' => $halt->id,
            'name' => $name,
            'lat' => $this->coordinate($lat),
            'lon' => $this->coordinate($lon),
            'valid_from' => $windowFrom->toDateString(),
            'valid_to' => $windowTo->toDateString(),
            // Die erste Sichtung ist nur eine Untergrenze; ein Wechsel dagegen ist beobachtet.
            'from_confirmed' => $aktuell !== null,
            'to_confirmed' => false,
        ]);
    }

    /**
     * Koordinate auf die Genauigkeit der Spalte (7 Nachkommastellen ≈ 1 cm) bringen.
     *
     * Nötig für den Vergleich: PostgreSQL liefert `decimal` als String zurück, SQLite (Testlauf)
     * als Float. Ein direkter Vergleich zwischen gelesenem und frisch berechnetem Wert wäre
     * je nach Treiber mal wahr, mal falsch.
     */
    private function coordinate(float $wert): string
    {
        return sprintf('%.7F', $wert);
    }

    /** Haversine-Distanz in Metern. */
    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = $phi2 - $phi1;
        $dLambda = deg2rad($lon2 - $lon1);

        $h = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($h)));
    }
}
