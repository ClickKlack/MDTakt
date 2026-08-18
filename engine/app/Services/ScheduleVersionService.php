<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PeriodOrigin;
use App\Enums\PeriodStatus;
use App\Models\LineVersion;
use App\Models\LineVersionInterval;
use App\Models\SchedulePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Schreibt Linien-Versionen und ihre beobachtete Gültigkeit fort (FAHRPLANPERIODEN §6.2).
 *
 * Ausgewertet wird **tagesweise**, nicht über einen repräsentativen Tag: Je Tag im
 * Feed-Fenster und je Linie wird der Fingerprint gebildet, gleiche aufeinanderfolgende
 * Vorkommen eines Typs werden zu Intervallen gebündelt. Nur so entstehen echte Grenzen —
 * und nur so lässt sich unterscheiden, ob ein Wechsel **im Fenster beobachtet** wurde
 * (gesichert) oder ob die Version bloß an der Fensterkante anlag (offen, §5.4 b).
 */
final class ScheduleVersionService
{
    public function __construct(
        private readonly ServiceDayResolver $serviceDays,
        private readonly FahrplanTypClassifier $classifier,
    ) {}

    /**
     * @return array{signatures: int, versions_created: int, intervals_written: int, lines_changed: int}
     */
    public function updateFromCurrentImport(): array
    {
        $window = $this->serviceDays->feedWindow();

        if ($window === null) {
            Log::warning('Version update without imported calendar');

            return ['signatures' => 0, 'versions_created' => 0, 'intervals_written' => 0, 'lines_changed' => 0];
        }

        $periode = $this->currentPeriod($window['from']);
        $fingerprints = $this->fingerprintsPerDay($window['from'], $window['to']);

        $versionenNeu = 0;
        $intervalle = 0;
        $geaenderteLinien = [];

        foreach ($fingerprints as $key => $tage) {
            [$line, $dayType] = explode("\0", $key);

            foreach ($this->foldIntoIntervals($tage) as $abschnitt) {
                $version = $this->findOrCreateVersion($periode, $line, $dayType, $abschnitt['fingerprint'], $neu);

                if ($neu) {
                    $versionenNeu++;
                    $geaenderteLinien[$line] = true;
                }

                $this->mergeInterval($version, $abschnitt);
                $intervalle++;
            }
        }

        // Viele Linien gleichzeitig geändert → Indiz für einen echten Fahrplanwechsel (§4.3).
        // Der Vorschlag selbst (Admin nimmt an/ab) folgt mit der Admin-Ansicht.
        if (count($geaenderteLinien) > 0) {
            Log::info('Line versions changed in this import', [
                'period_id' => $periode->id,
                'lines' => array_keys($geaenderteLinien),
                'count' => count($geaenderteLinien),
            ]);
        }

        return [
            'signatures' => DB::table('trip_signatures')->count(),
            'versions_created' => $versionenNeu,
            'intervals_written' => $intervalle,
            'lines_changed' => count($geaenderteLinien),
        ];
    }

    /**
     * Laufende Periode; legt beim allerersten Lauf eine an, weil die Konsolidierung eine
     * braucht, bevor ein Admin eine anlegen konnte (als `bootstrap` markiert).
     */
    private function currentPeriod(CarbonImmutable $from): SchedulePeriod
    {
        $periode = SchedulePeriod::query()->where('status', PeriodStatus::Current)->first();

        if ($periode !== null) {
            return $periode;
        }

        $periode = SchedulePeriod::query()->create([
            'label' => 'Ausgangsperiode ab '.$from->format('d.m.Y'),
            'valid_from' => $from->toDateString(),
            'valid_to' => null,
            'status' => PeriodStatus::Current,
            'created_via' => PeriodOrigin::Bootstrap,
        ]);

        Log::info('Bootstrap schedule period created', ['period_id' => $periode->id, 'valid_from' => $from->toDateString()]);

        return $periode;
    }

    /**
     * Fingerprint je (Linie, Fahrplantyp) und Tag: SHA über die sortierten Signaturen aller
     * Fahrten, die an diesem Tag auf dieser Linie verkehren.
     *
     * @return array<string, array<int, array{date: string, fingerprint: string}>> "line\0day_type" => Tage
     */
    private function fingerprintsPerDay(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $proTag = $this->serviceDays->activeServiceIdsForRange($from->toDateString(), $to->toDateString());

        // Fahrten mit Linie, Service und ihrer typbezogenen Signatur — einmal geladen.
        $fahrten = DB::table('trips')
            ->join('routes', 'routes.route_id', '=', 'trips.route_id')
            ->join('trip_signatures', 'trip_signatures.trip_id', '=', 'trips.trip_id')
            ->select('trips.service_id', 'routes.route_short_name as line', 'trip_signatures.day_type', 'trip_signatures.signature')
            ->get()
            ->groupBy(static fn (object $r): string => $r->service_id."\0".$r->day_type);

        $alleLinien = DB::table('routes')->distinct()->pluck('route_short_name');
        $result = [];

        foreach ($proTag as $date => $serviceIds) {
            // Tag ohne jeden Betrieb: eher eine Lücke in der Kalender-Abdeckung als ein
            // netzweiter Ausfall — daraus wird keine Beobachtung abgeleitet.
            if ($serviceIds === []) {
                continue;
            }

            $dayType = $this->classifier->classify(CarbonImmutable::parse($date))->value;

            // Signaturen des Tages je Linie sammeln.
            $jeLinie = [];
            foreach ($serviceIds as $serviceId) {
                foreach ($fahrten->get($serviceId."\0".$dayType, collect()) as $fahrt) {
                    $jeLinie[$fahrt->line][] = $fahrt->signature;
                }
            }

            foreach ($alleLinien as $line) {
                $signaturen = $jeLinie[$line] ?? [];

                // Fährt die Linie an diesem Tag nicht, während andere fahren, ist das eine
                // echte Beobachtung („kein Betrieb") — sie unterbricht die Gültigkeit, statt
                // sie stillschweigend über den Ausfalltag hinweg laufen zu lassen.
                if ($signaturen === []) {
                    $result[$line."\0".$dayType][] = ['date' => $date, 'fingerprint' => null];

                    continue;
                }

                sort($signaturen);
                $result[$line."\0".$dayType][] = [
                    'date' => $date,
                    'fingerprint' => hash('sha256', implode('|', $signaturen)),
                ];
            }
        }

        return $result;
    }

    /**
     * Bündelt aufeinanderfolgende Vorkommen eines Typs mit gleichem Fingerprint zu Intervallen.
     *
     * Die Tage eines Typs liegen nicht nebeneinander (Sonntage im Wochenabstand) — maßgeblich
     * ist die Reihenfolge der **Vorkommen**, nicht der Kalenderabstand. Eine Grenze gilt als
     * gesichert, wenn davor bzw. danach ein Vorkommen mit **anderem** Fingerprint im selben
     * Fenster liegt; am Rand des Fensters bleibt sie offen.
     *
     * Ein `fingerprint === null` steht für „Linie fährt an diesem Tag nicht". Solche
     * Abschnitte begrenzen die Nachbarn (der Wechsel ist beobachtet), erzeugen selbst aber
     * keine Version — „kein Betrieb" ist kein Fahrplanstand.
     *
     * @param  array<int, array{date: string, fingerprint: string|null}>  $tage
     * @return array<int, array{fingerprint: string, from: string, to: string, from_confirmed: bool, to_confirmed: bool}>
     */
    private function foldIntoIntervals(array $tage): array
    {
        usort($tage, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        $abschnitte = [];
        foreach ($tage as $tag) {
            $letzter = $abschnitte === [] ? null : array_key_last($abschnitte);

            if ($letzter !== null && $abschnitte[$letzter]['fingerprint'] === $tag['fingerprint']) {
                $abschnitte[$letzter]['to'] = $tag['date'];

                continue;
            }

            $abschnitte[] = [
                'fingerprint' => $tag['fingerprint'],
                'from' => $tag['date'],
                'to' => $tag['date'],
                'from_confirmed' => false,
                'to_confirmed' => false,
            ];
        }

        // Innere Grenzen sind beobachtete Wechsel, die äußeren liegen an der Fensterkante.
        foreach (array_keys($abschnitte) as $i) {
            $abschnitte[$i]['from_confirmed'] = $i > 0;
            $abschnitte[$i]['to_confirmed'] = $i < count($abschnitte) - 1;
        }

        return array_values(array_filter(
            $abschnitte,
            static fn (array $a): bool => $a['fingerprint'] !== null,
        ));
    }

    private function findOrCreateVersion(SchedulePeriod $periode, string $line, string $dayType, string $fingerprint, ?bool &$neu): LineVersion
    {
        $jetzt = CarbonImmutable::now();

        $version = LineVersion::query()
            ->where('period_id', $periode->id)
            ->where('line', $line)
            ->where('day_type', $dayType)
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($version !== null) {
            $neu = false;
            $version->update(['last_seen_at' => $jetzt]);

            return $version;
        }

        $neu = true;
        $naechste = (int) LineVersion::query()
            ->where('period_id', $periode->id)
            ->where('line', $line)
            ->where('day_type', $dayType)
            ->max('version_no');

        return LineVersion::query()->create([
            'period_id' => $periode->id,
            'line' => $line,
            'day_type' => $dayType,
            'version_no' => $naechste + 1,
            'fingerprint' => $fingerprint,
            'first_seen_at' => $jetzt,
            'last_seen_at' => $jetzt,
        ]);
    }

    /**
     * Hängt das beobachtete Intervall an die Version — überlappende oder direkt angrenzende
     * werden verschmolzen, eine Lücke bleibt eine Lücke (fehlender Import wird nicht
     * stillschweigend überbrückt).
     *
     * @param  array{fingerprint: string, from: string, to: string, from_confirmed: bool, to_confirmed: bool}  $abschnitt
     */
    private function mergeInterval(LineVersion $version, array $abschnitt): void
    {
        $von = CarbonImmutable::parse($abschnitt['from']);
        $bis = CarbonImmutable::parse($abschnitt['to']);

        $nachbarn = $version->intervals()
            ->whereDate('valid_from', '<=', $bis->addDay()->toDateString())
            ->whereDate('valid_to', '>=', $von->subDay()->toDateString())
            ->get();

        $vonBestaetigt = $abschnitt['from_confirmed'];
        $bisBestaetigt = $abschnitt['to_confirmed'];

        foreach ($nachbarn as $nachbar) {
            $nVon = CarbonImmutable::parse($nachbar->valid_from->toDateString());
            $nBis = CarbonImmutable::parse($nachbar->valid_to->toDateString());

            // Die Bestätigung gehört zur Grenze, die nach dem Verschmelzen übrig bleibt.
            if ($nVon->lessThan($von)) {
                $von = $nVon;
                $vonBestaetigt = $nachbar->from_confirmed;
            }
            if ($nBis->greaterThan($bis)) {
                $bis = $nBis;
                $bisBestaetigt = $nachbar->to_confirmed;
            }

            $nachbar->delete();
        }

        LineVersionInterval::query()->create([
            'line_version_id' => $version->id,
            'valid_from' => $von->toDateString(),
            'valid_to' => $bis->toDateString(),
            'from_confirmed' => $vonBestaetigt,
            'to_confirmed' => $bisBestaetigt,
        ]);
    }
}
