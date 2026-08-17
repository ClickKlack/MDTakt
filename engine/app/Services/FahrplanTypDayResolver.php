<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Löst einen Fahrplantyp in einen konkreten Stichtag des importierten Feeds auf.
 *
 * Hintergrund: Der Fahrplantyp hängt am **Datum** (Feiertag/Ferien/Wochentag), nicht an
 * der GTFS-`service_id` — ein Mo-Fr-Service kann Schul- und Ferientage zugleich abdecken.
 * Deshalb wird nicht der Service klassifiziert, sondern ein repräsentativer Tag gesucht
 * und darüber die an diesem Tag verkehrenden service_ids bestimmt (FAHRPLANPERIODEN §5.3).
 *
 * Repräsentativ heißt: die **häufigste Service-Zusammensetzung** unter allen Tagen dieses
 * Typs im Feed-Fenster — nicht der erste passende Tag. Der erste Tag ist regelmäßig der
 * untypischste, weil Umbrüche (Ferienende, Ende eines Schienenersatzverkehrs) am Anfang
 * des Fensters liegen, wenn der Feed kurz danach gezogen wurde. Im Feed vom 17.08.2026
 * traf das gleich zweimal zu: der 16.08. trug einen Sonderfahrplan, der 17.08. war der
 * Umstelltag von Bus- auf Tram-Betrieb der N2.
 *
 * Der rollierende ~2-Wochen-Feed von gtfs.de deckt regelmäßig **nicht alle vier Typen**
 * ab (FAHRPLANPERIODEN §8) — ein Typ ohne Stichtag ist daher ein normaler Zustand und
 * kein Fehler.
 */
final class FahrplanTypDayResolver
{
    public function __construct(
        private readonly FahrplanTypClassifier $classifier,
        private readonly ServiceDayResolver $serviceDays,
    ) {}

    /**
     * Repräsentativer Tag dieses Fahrplantyps im Feed-Fenster.
     * Null, wenn der Feed keinen solchen Tag mit Betrieb abdeckt.
     *
     * @return array{date: CarbonImmutable, service_ids: array<int, string>}|null
     */
    public function resolve(FahrplanTyp $typ): ?array
    {
        $window = $this->serviceDays->feedWindow();

        if ($window === null) {
            Log::warning('Schedule type lookup without imported calendar', ['day_type' => $typ->value]);

            return null;
        }

        $proTag = $this->serviceDays->activeServiceIdsForRange(
            $window['from']->toDateString(),
            $window['to']->toDateString(),
        );

        // Tage dieses Typs nach ihrer Service-Zusammensetzung bündeln. Die Signatur ist
        // die sortierte service_id-Liste — zwei Tage mit gleicher Signatur zeigen
        // denselben Fahrplan.
        $varianten = [];
        foreach ($proTag as $date => $serviceIds) {
            // Ein Tag zählt nur, wenn an ihm überhaupt etwas verkehrt.
            if ($serviceIds === [] || $this->classifier->classify(CarbonImmutable::parse($date)) !== $typ) {
                continue;
            }

            sort($serviceIds);
            $signatur = implode('|', $serviceIds);

            $varianten[$signatur] ??= ['dates' => [], 'service_ids' => $serviceIds];
            $varianten[$signatur]['dates'][] = $date;
        }

        if ($varianten === []) {
            Log::warning('Feed covers no day of the requested schedule type', [
                'day_type' => $typ->value,
                'window_from' => $window['from']->toDateString(),
                'window_to' => $window['to']->toDateString(),
            ]);

            return null;
        }

        // Häufigste Variante gewinnt; bei Gleichstand die mit dem früheren ersten Tag —
        // damit der Stichtag bei gleicher Datenlage stabil bleibt.
        uasort($varianten, static function (array $a, array $b): int {
            $byCount = count($b['dates']) <=> count($a['dates']);

            return $byCount !== 0 ? $byCount : $a['dates'][0] <=> $b['dates'][0];
        });

        $gewaehlt = reset($varianten);
        $referenceDate = $gewaehlt['dates'][0];
        $tageDesTyps = array_sum(array_map(static fn (array $v): int => count($v['dates']), $varianten));

        Log::debug('Schedule type resolved to reference date', [
            'day_type' => $typ->value,
            'reference_date' => $referenceDate,
            'service_count' => count($gewaehlt['service_ids']),
            // Wie eindeutig war die Wahl? Mehrere Varianten heißen: der Typ ist im Fenster
            // nicht einheitlich (Sonderfahrplan, Ersatzverkehr, Periodenwechsel).
            'matching_days' => count($gewaehlt['dates']),
            'days_of_type' => $tageDesTyps,
            'variant_count' => count($varianten),
        ]);

        return [
            'date' => CarbonImmutable::parse($referenceDate),
            'service_ids' => $gewaehlt['service_ids'],
        ];
    }
}
