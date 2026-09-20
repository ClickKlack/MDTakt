<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\GtfsTime;
use Illuminate\Support\Facades\DB;

/**
 * Ordnet eine Fahrt ihrem **Betriebstag** zu — nicht ihrem Kalendertag.
 *
 * Verkehrsbetriebe drücken das sonst über Zeiten jenseits 24:00 aus („26:00" für 2 Uhr des
 * folgenden Kalendertags, aber desselben Betriebstags). Der gtfs.de-Feed tut das **nicht**:
 * Keine einzige Fahrt im Bestand beginnt jenseits 24:00. Eine Nachtfahrt um 01:30 am Montag
 * steht dort als Montagsfahrt, gehört betrieblich aber zur Sonntagnacht.
 *
 * Die Folge ohne Korrektur (FAHRPLANPERIODEN §8): Der `mo_fr`-Strang einer Nachtlinie zerfällt,
 * weil montags die Sonntagnacht mitzählt. N1 bekam dadurch montags einen anderen Fingerprint
 * als Di–Fr — im Haltestellen-Editor erschienen an Herrenkrug 16 Fahrplanstände über vier
 * Wochen statt zweier.
 *
 * **Zwei Grenzen** (entschieden 20.09.2026, am Bestand gemessen): Tag- und Nachtnetz
 * überlappen von 03:45 bis 06:40, eine gemeinsame Grenze müsste dort etwas falsch zuordnen.
 * Zwischen 07:00 und 21:59 fährt dagegen keine Nachtlinie — jede Grenze dort ist eindeutig.
 * Siehe `config/mdtakt.php`.
 */
final class OperatingDayResolver
{
    /** @var array<string, int>|null trip_id => Startsekunde im Betriebstag */
    private ?array $starts = null;

    /** @var array<string, bool>|null trip_id => gehört zum Vortag */
    private ?array $shifts = null;

    /**
     * Gehört eine Linie zum Nachtnetz? Erkennbar am Buchstaben-Präfix (`N1`–`N9`) — dieselbe
     * Regel, nach der die Frontends das Nachtsignet wählen.
     */
    public function isNightLine(string $line): bool
    {
        return preg_match('/^\p{L}/u', $line) === 1;
    }

    /** Betriebstag-Grenze dieser Linie in Sekunden seit Mitternacht. */
    public function boundaryFor(string $line): int
    {
        $wert = $this->isNightLine($line)
            ? config('mdtakt.operating_day.night_line_boundary')
            : config('mdtakt.operating_day.day_line_boundary');

        return GtfsTime::toSeconds((string) $wert) ?? 0;
    }

    /**
     * Gehört eine Fahrt zum Betriebstag des Vortags?
     *
     * `null` als Startzeit heißt „unbekannt" — eine solche Fahrt bleibt bei ihrem Kalendertag,
     * statt sie auf eine Vermutung hin zu verschieben.
     */
    public function shiftsBack(string $line, ?int $startSeconds): bool
    {
        if ($startSeconds === null) {
            return false;
        }

        return $startSeconds < $this->boundaryFor($line);
    }

    /**
     * Sortierschlüssel einer GTFS-Zeit **innerhalb ihres Betriebstags**.
     *
     * Eine Zeit vor der Grenze der Linie gehört ans Ende des Betriebstags, nicht an seinen
     * Anfang: Auf der N1 fährt 22:49 vor 00:19, obwohl 00:19 als Uhrzeit kleiner ist. Der
     * Schlüssel schlägt in diesem Fall 24 Stunden auf.
     *
     * Zeiten, die schon jenseits 24:00 notiert sind (`25:10`), bleiben unverändert — sie
     * liegen ohnehin hinter der Grenze.
     *
     * Unlesbares sortiert ans Ende, damit eine kaputte Angabe nicht als „fährt zuerst" erscheint.
     */
    public function sortKey(string $line, ?string $time): int
    {
        $sekunden = GtfsTime::toSeconds($time);

        if ($sekunden === null) {
            return PHP_INT_MAX;
        }

        return $sekunden < $this->boundaryFor($line) ? $sekunden + 86400 : $sekunden;
    }

    /**
     * Startsekunde je Roh-Trip, aus der kleinsten `stop_sequence`.
     *
     * @return array<string, int>
     */
    public function tripStarts(): array
    {
        if ($this->starts !== null) {
            return $this->starts;
        }

        $starts = [];

        DB::table('stop_times')
            ->select('trip_id', 'stop_sequence', 'departure_time', 'arrival_time')
            ->orderBy('trip_id')
            ->orderBy('stop_sequence')
            ->chunk(20000, function ($rows) use (&$starts): void {
                foreach ($rows as $row) {
                    // Die erste gesehene Zeile je Trip ist dank der Sortierung die früheste
                    // Sequenz — spätere überschreiben sie nicht.
                    if (isset($starts[$row->trip_id])) {
                        continue;
                    }

                    $sekunden = GtfsTime::toSeconds($row->departure_time ?? $row->arrival_time);

                    if ($sekunden !== null) {
                        $starts[$row->trip_id] = $sekunden;
                    }
                }
            });

        return $this->starts = $starts;
    }

    /**
     * Welche Roh-Trips gehören zum Vortag?
     *
     * @return array<string, bool> trip_id => true
     */
    public function shiftMap(): array
    {
        if ($this->shifts !== null) {
            return $this->shifts;
        }

        $starts = $this->tripStarts();
        $shifts = [];

        DB::table('trips')
            ->join('routes', 'routes.route_id', '=', 'trips.route_id')
            ->select('trips.trip_id', 'routes.route_short_name as line')
            ->orderBy('trips.trip_id')
            ->chunk(20000, function ($rows) use (&$shifts, $starts): void {
                foreach ($rows as $row) {
                    if ($this->shiftsBack($row->line, $starts[$row->trip_id] ?? null)) {
                        $shifts[$row->trip_id] = true;
                    }
                }
            });

        return $this->shifts = $shifts;
    }

    /** Verwirft die gemerkten Werte — nötig, wenn der Roh-Bestand innerhalb eines Laufs wechselt. */
    public function forget(): void
    {
        $this->starts = null;
        $this->shifts = null;
    }
}
