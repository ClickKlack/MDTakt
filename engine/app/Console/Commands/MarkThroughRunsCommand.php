<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StopGroup;
use App\Models\TripLink;
use App\Services\ConsolidatedTripInfoResolver;
use App\Services\TripLinkService;
use Illuminate\Console\Command;

/**
 * Nachtrag für den Bestand vor K10: Anschlüsse an einem Tauschpunkt als Durchlauf kennzeichnen
 * (KURSE §2 K10, ROADMAP I-16).
 *
 * Ohne `--apply` nur Vorschau. Gedacht für Haltestellen, die der Pflegende als Tauschpunkt kennt
 * (City Carré, Listemannstraße) — an einer Wendeschleife mit nur einem Halt träfe das
 * Halt-Kriterium auch echte Wenden. Einzelne Fehlgriffe lassen sich im Board zurückschalten.
 */
final class MarkThroughRunsCommand extends Command
{
    protected $signature = 'trip-links:mark-through-runs
        {stop_group : Id oder Name der Haltestelle}
        {--apply : Wirklich schreiben statt nur anzeigen}';

    protected $description = 'Anschlüsse am Tauschpunkt nachträglich als Durchlauf kennzeichnen';

    public function handle(TripLinkService $links, ConsolidatedTripInfoResolver $tripInfo): int
    {
        $eingabe = (string) $this->argument('stop_group');

        $gruppe = ctype_digit($eingabe)
            ? StopGroup::query()->find((int) $eingabe)
            // Über den Anzeigenamen, nicht `name_key` — der ist anders normalisiert („citycarr").
            : StopGroup::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($eingabe)])->first();

        if ($gruppe === null) {
            $this->error("Haltestelle „{$eingabe}“ nicht gefunden.");

            return self::FAILURE;
        }

        $ids = $links->throughRunCandidates($gruppe);

        if ($ids === []) {
            $this->info("{$gruppe->name}: nichts nachzutragen.");

            return self::SUCCESS;
        }

        $zeilen = TripLink::query()->whereIn('id', $ids)->get(['id', 'from_trip_id', 'to_trip_id']);
        $info = $tripInfo->forIds($zeilen->flatMap(static fn ($l): array => [$l->from_trip_id, $l->to_trip_id])->all());

        $this->table(
            ['Anschluss', 'Ankunft', 'Abfahrt'],
            $zeilen->map(static function ($l) use ($info): array {
                $von = $info[$l->from_trip_id] ?? null;
                $nach = $info[$l->to_trip_id] ?? null;

                return [
                    $l->id,
                    $von === null ? '?' : "{$von['line']} an {$von['arrival_time']} (v{$von['version_no']})",
                    $nach === null ? '?' : "{$nach['line']} ab {$nach['departure_time']} (v{$nach['version_no']})",
                ];
            })->all(),
        );

        if (! $this->option('apply')) {
            $this->warn(count($ids)." Anschlüsse an {$gruppe->name} wären Durchläufe. Mit --apply schreiben.");

            return self::SUCCESS;
        }

        $anzahl = $links->markThroughRuns($ids);
        $this->info("{$anzahl} Anschlüsse an {$gruppe->name} als Durchlauf gekennzeichnet.");

        return self::SUCCESS;
    }
}
