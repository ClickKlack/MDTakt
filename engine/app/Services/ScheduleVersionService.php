<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PeriodOfferStatus;
use App\Enums\PeriodOrigin;
use App\Enums\PeriodStatus;
use App\Models\LineVersion;
use App\Models\LineVersionInterval;
use App\Models\PeriodChangeOffer;
use App\Models\SchedulePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
        private readonly SchedulePeriodService $periods,
        private readonly StopConsolidationService $stops,
        private readonly TripConsolidationService $trips,
        private readonly OperatingDayResolver $operatingDay,
    ) {}

    /**
     * @return array{signatures: int, versions_created: int, intervals_written: int, lines_changed: int, consolidated_stops: int, consolidated_trips: int}
     */
    public function updateFromCurrentImport(): array
    {
        $window = $this->serviceDays->feedWindow();

        if ($window === null) {
            Log::warning('Version update without imported calendar');

            return [
                'signatures' => 0, 'versions_created' => 0, 'intervals_written' => 0,
                'lines_changed' => 0, 'consolidated_stops' => 0, 'consolidated_trips' => 0,
            ];
        }

        $kette = $this->periodChain($window['from']);
        $auswertung = $this->fingerprintsPerDay($window['from'], $window['to']);
        $fingerprints = $auswertung['per_line'];

        $versionenNeu = 0;
        $intervalle = 0;
        $geaenderteLinien = [];
        $wechselProTag = [];
        $repraesentativeTage = [];

        foreach ($fingerprints as $key => $tage) {
            [$line, $dayType] = explode("\0", $key);

            // Ein Feed-Fenster kann eine Perioden-Grenze überspannen (23 Tage Fenster,
            // Fahrplanwechsel mittendrin). Deshalb wird die Periode **je Tag** bestimmt und
            // erst danach gebündelt — so endet ein Abschnitt am letzten wirklich beobachteten
            // Tag vor der Grenze, nicht am Kalendertag davor.
            foreach ($this->groupByPeriod($tage, $kette) as $lauf) {
                $abschnitte = $this->foldIntoIntervals($lauf['tage']);

                if ($abschnitte === []) {
                    continue;
                }

                // Eine Perioden-Grenze ist ein exakt bekanntes Datum — anders als eine
                // Fensterkante ist sie keine bloße Untergrenze.
                if ($lauf['cut_before']) {
                    $abschnitte[0]['from_confirmed'] = true;
                }
                if ($lauf['cut_after']) {
                    $abschnitte[array_key_last($abschnitte)]['to_confirmed'] = true;
                }

                foreach ($abschnitte as $abschnitt) {
                    $version = $this->findOrCreateVersion(
                        $lauf['period'], $line, $dayType, $abschnitt['fingerprint'], $neu
                    );

                    if ($neu) {
                        $versionenNeu++;
                        $geaenderteLinien[$line] = true;

                        // Nur ein **beobachteter** Wechsel zählt für den Periodenvorschlag.
                        // Beginnt die Version an der Fensterkante, ist ihr Anfang bloß eine
                        // Untergrenze — daraus lässt sich kein Wechseltag ableiten (§5.4 b).
                        if ($abschnitt['from_confirmed']) {
                            $wechselProTag[$abschnitt['from']][$line] = true;
                        }
                    }

                    $this->mergeInterval($version, $abschnitt);
                    $intervalle++;

                    // Ein Tag aus dem beobachteten Intervall genügt, um die Fahrten dieser
                    // Version zu greifen — innerhalb der Version ist der Fahrplan definitionsgemäß
                    // derselbe.
                    $repraesentativeTage[$version->id] ??= $abschnitt['from'];
                }
            }
        }

        if (count($geaenderteLinien) > 0) {
            Log::info('Line versions changed in this import', [
                'lines' => array_map(strval(...), array_keys($geaenderteLinien)),
                'count' => count($geaenderteLinien),
            ]);
        }

        // Viele Linien am selben Tag geändert → Indiz für einen echten Fahrplanwechsel (§4.3).
        $this->offerPeriodChanges($wechselProTag, $auswertung['active_lines_per_day']);

        // Phase C: erst hier überleben die Fahrplan-**Inhalte** den nächsten Import. Bis
        // dahin hielt das Konsolidat nur fest, dass und wann sich etwas geändert hat.
        $stopMap = $this->stops->consolidate($window['from'], $window['to']);
        $fahrten = $this->trips->consolidate($repraesentativeTage, $stopMap);

        return [
            'signatures' => DB::table('trip_signatures')->count(),
            'versions_created' => $versionenNeu,
            'intervals_written' => $intervalle,
            'lines_changed' => count($geaenderteLinien),
            'consolidated_stops' => count($stopMap),
            'consolidated_trips' => $fahrten['consolidated_trips'],
        ];
    }

    /**
     * Die Perioden-Kette, aufsteigend nach `valid_from`. Beim allerersten Lauf entsteht eine
     * `bootstrap`-Periode, weil die Konsolidierung eine braucht, bevor ein Admin eine anlegen
     * konnte.
     *
     * @return Collection<int, SchedulePeriod>
     */
    private function periodChain(CarbonImmutable $from): Collection
    {
        $kette = SchedulePeriod::query()->orderBy('valid_from')->get();

        if ($kette->isNotEmpty()) {
            return $kette;
        }

        return collect([$this->bootstrapPeriod($from)]);
    }

    /**
     * Gruppiert die Tage einer (Linie, Fahrplantyp) in Läufe je Fahrplanperiode.
     *
     * @param  array<int, array{date: string, fingerprint: string|null}>  $tage
     * @param  Collection<int, SchedulePeriod>  $kette
     * @return array<int, array{period: SchedulePeriod, tage: array<int, array{date: string, fingerprint: string|null}>, cut_before: bool, cut_after: bool}>
     */
    private function groupByPeriod(array $tage, Collection $kette): array
    {
        usort($tage, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        $laeufe = [];
        foreach ($tage as $tag) {
            $periode = $this->periodForDay($tag['date'], $kette);
            $letzter = $laeufe === [] ? null : array_key_last($laeufe);

            if ($letzter !== null && $laeufe[$letzter]['period']->id === $periode->id) {
                $laeufe[$letzter]['tage'][] = $tag;

                continue;
            }

            $laeufe[] = ['period' => $periode, 'tage' => [$tag], 'cut_before' => false, 'cut_after' => false];
        }

        foreach (array_keys($laeufe) as $i) {
            $laeufe[$i]['cut_before'] = $i > 0;
            $laeufe[$i]['cut_after'] = $i < count($laeufe) - 1;
        }

        return $laeufe;
    }

    /**
     * Die Periode, die ein Tag trägt: die jüngste, die nicht nach ihm beginnt. Tage vor der
     * kuratierten Kette fallen an die erste Periode — ein Import darf nicht daran scheitern,
     * dass sein Fenster weiter zurückreicht als die Kette.
     *
     * @param  Collection<int, SchedulePeriod>  $kette
     */
    private function periodForDay(string $datum, Collection $kette): SchedulePeriod
    {
        $treffer = null;

        foreach ($kette as $periode) {
            if ($periode->valid_from->toDateString() <= $datum) {
                $treffer = $periode;
            }
        }

        return $treffer ?? $kette->first();
    }

    /** Erstlauf-Periode, sichtbar als solche markiert (PeriodOrigin::Bootstrap). */
    private function bootstrapPeriod(CarbonImmutable $from): SchedulePeriod
    {
        $periode = SchedulePeriod::query()->create([
            'label' => 'Ausgangsperiode ab '.$from->format('d.m.Y'),
            'valid_from' => $from->toDateString(),
            'valid_to' => null,
            'status' => PeriodStatus::Current,
            'created_via' => PeriodOrigin::Bootstrap,
        ]);

        Log::info('Bootstrap schedule period created', ['period_id' => $periode->id, 'valid_from' => $from->toDateString()]);

        // Gültigkeit und Status kommen aus einer Hand — sonst laufen die beiden Stellen,
        // die die Kette schreiben, beim ersten Verschieben einer Grenze auseinander.
        $this->periods->rebuildChain();

        return $periode->refresh();
    }

    /**
     * Fingerprint je (Linie, Fahrplantyp) und Tag: SHA über die sortierten Signaturen aller
     * Fahrten, die an diesem Tag auf dieser Linie verkehren.
     *
     * Nebenbei entsteht die Zahl der Linien, die an einem Tag überhaupt verkehren — Bezugsgröße
     * für den Periodenwechsel-Vorschlag (§4.3).
     *
     * @return array{per_line: array<string, array<int, array{date: string, fingerprint: string|null}>>, active_lines_per_day: array<string, int>}
     */
    private function fingerprintsPerDay(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Einen Tag über das Fenster hinaus laden: Der Betriebstag `to` endet erst in der
        // Nacht auf `to + 1`, und deren Fahrten stehen im Feed unter dem Folgetag.
        $proTag = $this->serviceDays->activeServiceIdsForRange(
            $from->toDateString(),
            $to->addDay()->toDateString(),
        );

        $verschoben = $this->operatingDay->shiftMap();

        // Fahrten mit Linie, Service und ihrer typbezogenen Signatur — einmal geladen.
        // Zusätzlich nach der Seite der Betriebstag-Grenze getrennt: Eine Fahrt vor der
        // Grenze zählt zum Betriebstag des Vortags, muss also beim Tag davor eingesammelt
        // werden, nicht bei ihrem Kalendertag.
        $fahrten = DB::table('trips')
            ->join('routes', 'routes.route_id', '=', 'trips.route_id')
            ->join('trip_signatures', 'trip_signatures.trip_id', '=', 'trips.trip_id')
            ->select(
                'trips.trip_id', 'trips.service_id', 'routes.route_short_name as line',
                'trip_signatures.day_type', 'trip_signatures.signature',
            )
            ->get()
            ->groupBy(static fn (object $r): string => $r->service_id."\0".$r->day_type
                ."\0".(isset($verschoben[$r->trip_id]) ? 'previous' : 'same'));

        $alleLinien = DB::table('routes')->distinct()->pluck('route_short_name');
        $result = [];
        $aktiveLinien = [];

        $letzterTag = $to->toDateString();

        foreach ($proTag as $date => $serviceIds) {
            // Der Zusatztag hinter dem Fenster liefert nur die Nacht des letzten Betriebstags;
            // als eigener Betriebstag wäre er unvollständig beobachtet.
            if ($date > $letzterTag) {
                continue;
            }

            // Tag ohne jeden Betrieb: eher eine Lücke in der Kalender-Abdeckung als ein
            // netzweiter Ausfall — daraus wird keine Beobachtung abgeleitet.
            if ($serviceIds === []) {
                continue;
            }

            $dayType = $this->classifier->classify(CarbonImmutable::parse($date))->value;

            // Signaturen des **Betriebstags** je Linie sammeln: die Fahrten dieses
            // Kalendertags ab der Grenze, dazu die Fahrten des Folgetags davor — das ist die
            // Nacht, die betrieblich noch zu diesem Tag gehört.
            $jeLinie = [];

            foreach ($serviceIds as $serviceId) {
                foreach ($fahrten->get($serviceId."\0".$dayType."\0same", collect()) as $fahrt) {
                    $jeLinie[$fahrt->line][] = $fahrt->signature;
                }
            }

            $folgetag = CarbonImmutable::parse($date)->addDay()->toDateString();

            foreach ($proTag[$folgetag] ?? [] as $serviceId) {
                foreach ($fahrten->get($serviceId."\0".$dayType."\0previous", collect()) as $fahrt) {
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

                $aktiveLinien[$date] = ($aktiveLinien[$date] ?? 0) + 1;
                sort($signaturen);
                $result[$line."\0".$dayType][] = [
                    'date' => $date,
                    'fingerprint' => hash('sha256', implode('|', $signaturen)),
                ];
            }
        }

        return ['per_line' => $result, 'active_lines_per_day' => $aktiveLinien];
    }

    /**
     * Legt Periodenwechsel-Vorschläge an (§4.3): Ändern sich an einem Tag mindestens
     * `mdtakt.consolidation.period_offer_min_share` der dort verkehrenden Linien, ist das ein
     * Indiz für einen echten Fahrplanwechsel statt für einzelne Baustellen.
     *
     * Das System legt die Periode **nicht** selbst an — der Admin entscheidet. Ein bereits
     * beschiedener Tag wird nicht erneut vorgeschlagen (unique auf `suggested_from`).
     *
     * @param  array<string, array<string, bool>>  $wechselProTag  Datum => betroffene Linien
     * @param  array<string, int>  $aktiveLinien  Datum => Zahl der an dem Tag verkehrenden Linien
     */
    private function offerPeriodChanges(array $wechselProTag, array $aktiveLinien): void
    {
        $schwelle = (float) config('mdtakt.consolidation.period_offer_min_share');

        foreach ($wechselProTag as $datum => $linien) {
            $aktiv = $aktiveLinien[$datum] ?? 0;
            $betroffen = count($linien);

            if ($aktiv === 0 || $betroffen / $aktiv < $schwelle) {
                continue;
            }

            // `whereDate` statt Gleichheit: Der `date`-Cast schreibt `Y-m-d H:i:s`; PostgreSQL
            // wirft die Uhrzeit in der `date`-Spalte weg, SQLite (Testlauf) behält sie.
            if (PeriodChangeOffer::query()->whereDate('suggested_from', $datum)->exists()) {
                continue;
            }

            // Linien-Namen sind Strings, auch wenn sie wie Zahlen aussehen — als Array-Schlüssel
            // hat PHP sie zu int gemacht. natsort sortiert „1, 2, 10" statt „1, 10, 2".
            $namen = array_map(strval(...), array_keys($linien));
            usort($namen, strnatcmp(...));

            PeriodChangeOffer::query()->create([
                'suggested_from' => $datum,
                'changed_line_count' => $betroffen,
                'active_line_count' => $aktiv,
                'lines' => $namen,
                'status' => PeriodOfferStatus::Open,
            ]);

            Log::info('Period change offered', [
                'suggested_from' => $datum,
                'changed_lines' => $betroffen,
                'active_lines' => $aktiv,
                'share' => round($betroffen / $aktiv, 3),
            ]);
        }
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

            // Die Bestätigung gehört zur Grenze, die nach dem Verschmelzen übrig bleibt —
            // und sie ist die Aufzeichnung einer Beobachtung, also nie rücknehmbar. Fällt ein
            // späterer Lauf mit seiner Fensterkante auf einen bereits gesicherten Wechseltag,
            // darf seine Unkenntnis die frühere Beobachtung nicht überschreiben.
            if ($nVon->lessThan($von)) {
                $von = $nVon;
                $vonBestaetigt = $nachbar->from_confirmed;
            } elseif ($nVon->equalTo($von)) {
                $vonBestaetigt = $vonBestaetigt || $nachbar->from_confirmed;
            }

            if ($nBis->greaterThan($bis)) {
                $bis = $nBis;
                $bisBestaetigt = $nachbar->to_confirmed;
            } elseif ($nBis->equalTo($bis)) {
                $bisBestaetigt = $bisBestaetigt || $nachbar->to_confirmed;
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

        $this->displaceOtherVersions($version, $von, $bis);
    }

    /**
     * Verdrängt andere Versionen desselben Strangs aus den soeben belegten Tagen.
     *
     * Ein Tag trägt genau einen Fahrplan. Beobachtet ein Lauf für einen Tag einen anderen
     * Fingerprint als ein früherer, gewinnt der neue Lauf (FAHRPLANPERIODEN §5.3) — das
     * ältere Intervall muss weichen, sonst beanspruchen zwei Versionen denselben Tag und
     * datumsbezogene Abfragen liefern die Fahrten doppelt.
     *
     * Das kommt vor: Ein späterer Feed-Build revidiert den Fahrplan rückwirkend. Real
     * gemessen am 20.09.2026 — Linie 4, Mo-Fr, 31.08.–18.09., eine Fahrt weniger als eine
     * Woche zuvor.
     *
     * Die entstehenden Schnittkanten gelten als **offen**: Dass an diesem Tag ein anderer
     * Fahrplan gilt, wissen wir aus zwei verschiedenen Läufen — beobachtet wurde der Wechsel
     * damit nicht, und eine Bestätigung wäre eine Behauptung (§5.4 b).
     */
    private function displaceOtherVersions(LineVersion $version, CarbonImmutable $von, CarbonImmutable $bis): void
    {
        // Perioden sind zeitlich disjunkt — ein Konflikt kann nur innerhalb einer entstehen.
        $fremdeVersionen = LineVersion::query()
            ->where('period_id', $version->period_id)
            ->where('line', $version->line)
            ->where('day_type', $version->day_type)
            ->whereKeyNot($version->id)
            ->pluck('id');

        if ($fremdeVersionen->isEmpty()) {
            return;
        }

        $betroffen = LineVersionInterval::query()
            ->whereIn('line_version_id', $fremdeVersionen)
            ->whereDate('valid_from', '<=', $bis->toDateString())
            ->whereDate('valid_to', '>=', $von->toDateString())
            ->get();

        foreach ($betroffen as $fremd) {
            $fVon = CarbonImmutable::parse($fremd->valid_from->toDateString());
            $fBis = CarbonImmutable::parse($fremd->valid_to->toDateString());
            $restLinks = $fVon->lessThan($von);
            $restRechts = $fBis->greaterThan($bis);
            $endeBestaetigt = (bool) $fremd->to_confirmed;

            if (! $restLinks && ! $restRechts) {
                // Vollständig verdrängt. Die Version selbst bleibt mitsamt ihren Fahrten
                // bestehen — sie hält fest, was der Feed einmal behauptet hat, und ist über
                // ihre Versions-ID weiterhin abrufbar (entschieden 20.09.2026).
                $fremd->delete();

                continue;
            }

            if ($restLinks) {
                $fremd->update([
                    'valid_to' => $von->subDay()->toDateString(),
                    'to_confirmed' => false,
                ]);
            }

            if ($restRechts) {
                if ($restLinks) {
                    // Der neue Fahrplan schneidet mitten heraus — der rechte Rest wird eigenständig.
                    LineVersionInterval::query()->create([
                        'line_version_id' => $fremd->line_version_id,
                        'valid_from' => $bis->addDay()->toDateString(),
                        'valid_to' => $fBis->toDateString(),
                        'from_confirmed' => false,
                        'to_confirmed' => $endeBestaetigt,
                    ]);
                } else {
                    $fremd->update([
                        'valid_from' => $bis->addDay()->toDateString(),
                        'from_confirmed' => false,
                    ]);
                }
            }
        }

        if ($betroffen->isNotEmpty()) {
            Log::debug('Older versions displaced from reobserved days', [
                'line' => $version->line,
                'day_type' => $version->day_type->value,
                'winning_version_id' => $version->id,
                'from' => $von->toDateString(),
                'to' => $bis->toDateString(),
                'intervals_touched' => $betroffen->count(),
            ]);
        }
    }
}
