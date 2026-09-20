<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\FahrplanTyp;
use App\Enums\StopGroupOrigin;
use App\Models\ConsolidatedStop;
use App\Models\ConsolidatedStopTime;
use App\Models\ConsolidatedStopVersion;
use App\Models\ConsolidatedTrip;
use App\Models\LineVersion;
use App\Models\LineVersionInterval;
use App\Models\SchedulePeriod;
use App\Models\StopGroup;
use App\Models\StopGroupMember;

/**
 * Baut Konsolidat-Testdaten direkt, ohne den Umweg über Import und Konsolidierung.
 *
 * Der übrige Testbestand erzeugt das Konsolidat über die echte Pipeline (Roh-Factories →
 * TripSignatureService → ScheduleVersionService). Das ist für die Konsolidierung selbst richtig —
 * für Fahrplan- und Diff-Tests wären es je Szenario rund 60 Zeilen Aufbau, und die Zeiten ließen
 * sich nur mittelbar steuern. Hier zählt die Haltefolge, nicht ihr Zustandekommen.
 */
final class ConsolidatedFixtures
{
    /** @var array<string, ConsolidatedStop> Haltname => Identität, damit Namen wiederverwendet werden */
    private array $halte = [];

    /** @var array<string, StopGroup> Name => Haltestelle (Betriebspunkt) */
    private array $gruppen = [];

    private ?SchedulePeriod $periode = null;

    public function periode(): SchedulePeriod
    {
        return $this->periode ??= SchedulePeriod::factory()->create();
    }

    public function version(
        string $line = '1',
        FahrplanTyp $dayType = FahrplanTyp::MoFrNormal,
        int $versionNo = 1,
        ?SchedulePeriod $periode = null,
    ): LineVersion {
        return LineVersion::factory()->create([
            'period_id' => ($periode ?? $this->periode())->id,
            'line' => $line,
            'day_type' => $dayType,
            'version_no' => $versionNo,
        ]);
    }

    /**
     * Halt-Identität samt Namensversion; derselbe Name liefert dieselbe Identität zurück.
     * So lässt sich eine Haltefolge schlicht über Namen schreiben.
     */
    public function halt(string $name): ConsolidatedStop
    {
        if (isset($this->halte[$name])) {
            return $this->halte[$name];
        }

        $halt = ConsolidatedStop::factory()->create(['name_key' => mb_strtolower($name)]);
        ConsolidatedStopVersion::factory()->create([
            'consolidated_stop_id' => $halt->id,
            'name' => $name,
        ]);

        // Jeder Halt gehört zu einer Haltestelle — wie im Betrieb, wo die Automatik das beim
        // Import erledigt. Ohne sie ließe sich an diesem Halt kein Anschluss bilden.
        $this->zurHaltestelle($halt, $this->haltestelle($name));

        return $this->halte[$name] = $halt;
    }

    /**
     * Die Haltestelle (Betriebspunkt) zu einem Namen; derselbe Name liefert dieselbe zurück.
     */
    public function haltestelle(string $name): StopGroup
    {
        return $this->gruppen[$name] ??= StopGroup::factory()->create([
            'name' => $name,
            'name_key' => mb_strtolower($name),
        ]);
    }

    /**
     * Ordnet einen Halt einer Haltestelle zu — der Weg, um im Test zwei Bahnsteige unter einen
     * Betriebspunkt zu bekommen (wie „Rothensee" und „Rothensee (Schleife)").
     */
    public function zurHaltestelle(ConsolidatedStop $halt, StopGroup $gruppe): void
    {
        StopGroupMember::query()->updateOrCreate(
            ['consolidated_stop_id' => $halt->id],
            ['stop_group_id' => $gruppe->id, 'assigned_via' => StopGroupOrigin::Auto],
        );
    }

    /**
     * Beobachtete Gültigkeit einer Version. Ohne sie gilt eine Version an keinem Tag — was
     * für die Umlauf-Pflege bedeutet, dass sich zwei Versionen nie überschneiden können.
     */
    public function gueltigkeit(
        LineVersion $version,
        string $von = '2026-08-17',
        string $bis = '2026-09-18',
    ): LineVersionInterval {
        return LineVersionInterval::factory()->create([
            'line_version_id' => $version->id,
            'valid_from' => $von,
            'valid_to' => $bis,
        ]);
    }

    /**
     * Eine Fahrt aus paarweise angegebenen Halten und Zeiten.
     *
     * Ein Haltname darf mehrfach vorkommen — das ist der Wendeschleifen-Fall, der im
     * Realbestand 1.022 von 18.193 Fahrten betrifft.
     *
     * @param  array<int, string>  $halte  Haltnamen in Reihenfolge
     * @param  array<int, string>  $zeiten  GTFS-Zeiten, positionsgleich zu $halte
     */
    public function fahrt(LineVersion $version, array $halte, array $zeiten, ?string $signature = null): ConsolidatedTrip
    {
        if (count($halte) !== count($zeiten)) {
            throw new \InvalidArgumentException('Halte und Zeiten müssen gleich lang sein.');
        }

        $identitaeten = array_map($this->halt(...), $halte);

        $fahrt = ConsolidatedTrip::factory()->create([
            'line_version_id' => $version->id,
            'signature' => $signature ?? hash('sha256', $version->line.'|'.$version->day_type->value.'|'.implode(',', $zeiten)),
            'first_stop_id' => $identitaeten[0]->id,
            'last_stop_id' => $identitaeten[count($identitaeten) - 1]->id,
        ]);

        foreach ($identitaeten as $i => $halt) {
            ConsolidatedStopTime::factory()->create([
                'consolidated_trip_id' => $fahrt->id,
                'stop_id' => $halt->id,
                'stop_sequence' => $i + 1,
                'arrival_time' => $zeiten[$i],
                'departure_time' => $zeiten[$i],
            ]);
        }

        return $fahrt;
    }
}
