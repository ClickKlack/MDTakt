<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Models\LineVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Beantwortet die Frage, für welche Zeiträume das Konsolidat den Fahrplan wirklich hergibt —
 * und wo Lücken klaffen (FAHRPLANPERIODEN Phase C).
 *
 * Der Feed ist ein rollierendes Fenster; jede nicht importierte Woche fehlt endgültig. Eine
 * Abdeckungs-Anzeige, die Lücken verschweigt, wäre deshalb schlimmer als keine: Sie ließe einen
 * unvollständigen Bestand vollständig aussehen.
 *
 * **Abgedeckt heißt hier: mit Inhalt.** Eine Linien-Version aus Phase B belegt nur, *dass* an
 * diesen Tagen ein bestimmter Fahrplan galt. Erst konsolidierte Fahrten machen ihn abrufbar —
 * Versionen ohne Inhalt werden getrennt ausgewiesen statt mitgezählt.
 */
final class CoverageService
{
    public function __construct(private readonly FahrplanTypClassifier $classifier) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $versionen = LineVersion::query()
            ->withCount('consolidatedTrips')
            ->with(['intervals' => fn ($q) => $q->orderBy('valid_from')])
            ->get();

        if ($versionen->isEmpty()) {
            return ['window' => null, 'totals' => $this->totals($versionen), 'lines' => []];
        }

        $lines = $versionen
            ->groupBy('line')
            ->map(fn (Collection $proLinie, string $line): array => [
                'line' => $line,
                'day_types' => $this->perDayType($proLinie),
            ])
            ->sortKeys()
            ->values()
            ->all();

        return [
            'window' => $this->window($versionen),
            'totals' => $this->totals($versionen),
            'lines' => $lines,
        ];
    }

    /**
     * @param  Collection<int, LineVersion>  $proLinie
     * @return array<int, array<string, mixed>>
     */
    private function perDayType(Collection $proLinie): array
    {
        $result = [];

        foreach (FahrplanTyp::cases() as $typ) {
            $versionen = $proLinie->where('day_type', $typ);

            if ($versionen->isEmpty()) {
                continue;
            }

            $mitInhalt = $versionen->filter(fn (LineVersion $v): bool => $v->consolidated_trips_count > 0);

            $abschnitte = $this->merge(
                $mitInhalt->flatMap->intervals->map(fn ($i): array => [
                    'from' => $i->valid_from->toDateString(),
                    'to' => $i->valid_to->toDateString(),
                    'from_confirmed' => (bool) $i->from_confirmed,
                    'to_confirmed' => (bool) $i->to_confirmed,
                ])->all(),
                $typ,
            );

            $result[] = [
                'day_type' => $typ->value,
                'day_type_label' => $typ->label(),
                'ranges' => $abschnitte,
                'gaps' => $this->gaps($abschnitte, $typ),
                'versions_total' => $versionen->count(),
                'versions_without_content' => $versionen->count() - $mitInhalt->count(),
            ];
        }

        return $result;
    }

    /**
     * Verschmilzt Abschnitte, zwischen denen die Abdeckung nicht wirklich abreißt.
     *
     * Maßgeblich ist nicht der Kalenderabstand, sondern der Fahrplantyp: Zwischen einem
     * Samstag und dem nächsten liegen sechs Tage ohne einen einzigen Samstag — für den
     * `sa`-Strang ist das keine Unterbrechung, sondern lückenlose Abdeckung. Nach
     * Kalendertagen gerechnet zerfiele jede Wochenend-Linie in lauter Ein-Tages-Abschnitte.
     *
     * @param  array<int, array{from: string, to: string, from_confirmed: bool, to_confirmed: bool}>  $abschnitte
     * @return array<int, array{from: string, to: string, from_confirmed: bool, to_confirmed: bool}>
     */
    private function merge(array $abschnitte, FahrplanTyp $typ): array
    {
        if ($abschnitte === []) {
            return [];
        }

        usort($abschnitte, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        $ergebnis = [array_shift($abschnitte)];

        foreach ($abschnitte as $abschnitt) {
            $letzter = count($ergebnis) - 1;

            $reisstAb = $this->countDaysOfType(
                CarbonImmutable::parse($ergebnis[$letzter]['to'])->addDay(),
                CarbonImmutable::parse($abschnitt['from'])->subDay(),
                $typ,
            ) > 0;

            if ($reisstAb) {
                $ergebnis[] = $abschnitt;

                continue;
            }

            if ($abschnitt['to'] > $ergebnis[$letzter]['to']) {
                $ergebnis[$letzter]['to'] = $abschnitt['to'];
                $ergebnis[$letzter]['to_confirmed'] = $abschnitt['to_confirmed'];
            }
        }

        return $ergebnis;
    }

    /** Tage eines Fahrplantyps in einem Zeitraum (leer, wenn von > bis). */
    private function countDaysOfType(CarbonImmutable $von, CarbonImmutable $bis, FahrplanTyp $typ): int
    {
        $anzahl = 0;

        for ($tag = $von; ! $tag->greaterThan($bis); $tag = $tag->addDay()) {
            if ($this->classifier->classify($tag) === $typ) {
                $anzahl++;
            }
        }

        return $anzahl;
    }

    /**
     * Echte Lücken zwischen den Abschnitten.
     *
     * Entscheidend: Ein Zwischenraum zählt nur, wenn dort überhaupt ein Tag dieses
     * Fahrplantyps liegt. Zwischen Freitag und Montag klafft für `mo_fr` keine Lücke —
     * das Wochenende gehört schlicht nicht zu diesem Strang.
     *
     * @param  array<int, array{from: string, to: string, from_confirmed: bool, to_confirmed: bool}>  $abschnitte
     * @return array<int, array{from: string, to: string, days: int}>
     */
    private function gaps(array $abschnitte, FahrplanTyp $typ): array
    {
        $luecken = [];

        for ($i = 0; $i < count($abschnitte) - 1; $i++) {
            $von = CarbonImmutable::parse($abschnitte[$i]['to'])->addDay();
            $bis = CarbonImmutable::parse($abschnitte[$i + 1]['from'])->subDay();

            $betroffen = $this->countDaysOfType($von, $bis, $typ);

            if ($betroffen > 0) {
                $luecken[] = ['from' => $von->toDateString(), 'to' => $bis->toDateString(), 'days' => $betroffen];
            }
        }

        return $luecken;
    }

    /**
     * @param  Collection<int, LineVersion>  $versionen
     * @return array{from: string, to: string}|null
     */
    private function window(Collection $versionen): ?array
    {
        $intervalle = $versionen->flatMap->intervals;

        if ($intervalle->isEmpty()) {
            return null;
        }

        return [
            'from' => $intervalle->min('valid_from')->toDateString(),
            'to' => $intervalle->max('valid_to')->toDateString(),
        ];
    }

    /**
     * @param  Collection<int, LineVersion>  $versionen
     * @return array<string, int>
     */
    private function totals(Collection $versionen): array
    {
        $ohneInhalt = $versionen->filter(fn (LineVersion $v): bool => $v->consolidated_trips_count === 0);

        return [
            'lines' => $versionen->pluck('line')->unique()->count(),
            'versions' => $versionen->count(),
            'versions_without_content' => $ohneInhalt->count(),
            'consolidated_trips' => $versionen->sum('consolidated_trips_count'),
        ];
    }
}
