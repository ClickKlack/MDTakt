<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SightingMatch;
use App\Enums\SightingStatus;
use App\Models\ConsolidatedTrip;
use App\Models\Sighting;

/**
 * Offene Sichtungen je Fahrt, für die Zeile „Sichtungen" im Fahrplan.
 *
 * Gruppiert nach Kursnummer: Drei Sichtungen „04" an einer Fahrt sind **eine** Aussage mit drei
 * Belegen und werden gemeinsam entschieden. Nennen Sichtungen derselben Fahrt verschiedene
 * Nummern, stehen beide Gruppen nebeneinander — genau der Fall, den man im Fahrplan sehen will.
 *
 * Angelehnt an {@see CourseLookup::forTrips()}: ein Aufruf je Fahrplan, nicht je Spalte.
 */
final class SightingLookup
{
    public function __construct(
        private readonly CourseLookup $courses,
        private readonly TripLinkService $links,
    ) {}

    /**
     * @param  array<int, int>  $tripIds
     * @param  string  $line  Linie der Fahrten — für den Anzeige-Präfix
     * @return array<int, array<int, array{number: string, display: string, count: int, ids: array<int, int>, dates: array<int, string>, stops: array<int, string>, comparison: string, next_version: bool, chain_trip_count: ?int}>>
     */
    public function pendingForTrips(array $tripIds, string $line): array
    {
        $eindeutig = array_values(array_unique(array_filter($tripIds)));

        if ($eindeutig === []) {
            return [];
        }

        $sichtungen = Sighting::query()
            ->where('status', SightingStatus::Pending->value)
            ->whereIn('match', [SightingMatch::Matched->value, SightingMatch::MatchedNextVersion->value])
            ->whereIn('consolidated_trip_id', $eindeutig)
            ->orderBy('service_date')
            ->get();

        if ($sichtungen->isEmpty()) {
            return [];
        }

        $kurse = $this->courses->forTrips($sichtungen->pluck('consolidated_trip_id')->all());
        $gruppen = [];

        foreach ($sichtungen as $s) {
            $tripId = (int) $s->consolidated_trip_id;
            $schluessel = $this->key($s->course_number);
            $gruppe = $gruppen[$tripId][$schluessel] ?? [
                'number' => $s->course_number,
                'display' => $line.'/'.$s->course_number,
                'count' => 0,
                'ids' => [],
                'dates' => [],
                'stops' => [],
                'comparison' => $this->comparison($kurse[$tripId]['number'] ?? null, $s->course_number),
                'next_version' => false,
                'chain_trip_count' => null,
            ];

            $gruppe['count']++;
            $gruppe['ids'][] = $s->id;
            $gruppe['dates'][] = $s->service_date->toDateString();
            $gruppe['stops'][] = $s->stop_name ?? $s->hafas_stop_id;
            $gruppe['next_version'] = $gruppe['next_version'] || $s->match === SightingMatch::MatchedNextVersion;

            $gruppen[$tripId][$schluessel] = $gruppe;
        }

        $ketten = [];

        foreach ($gruppen as $tripId => $jeNummer) {
            foreach ($jeNummer as $schluessel => $gruppe) {
                $gruppe['dates'] = array_values(array_unique($gruppe['dates']));
                $gruppe['stops'] = array_values(array_unique($gruppe['stops']));

                // Die Kettenlänge steht in der Rückfrage vor dem Umnummerieren.
                if ($gruppe['comparison'] === 'differs') {
                    $gruppe['chain_trip_count'] = $ketten[$tripId]
                        ??= count($this->links->chainFor(ConsolidatedTrip::query()->findOrFail($tripId)));
                }

                $jeNummer[$schluessel] = $gruppe;
            }

            // Die meistgenannte Nummer zuerst — sie steht im Chip, der Rest hinter „+n".
            usort($jeNummer, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $gruppen[$tripId] = $jeNummer;
        }

        return $gruppen;
    }

    private function key(string $nummer): string
    {
        $nummer = trim($nummer);

        return ctype_digit($nummer) ? (ltrim($nummer, '0') ?: '0') : mb_strtolower($nummer);
    }

    private function comparison(?string $lokal, string $gesichtet): string
    {
        if ($lokal === null) {
            return 'none';
        }

        return SightingIngestService::sameNumber($lokal, $gesichtet) ? 'same' : 'differs';
    }
}
