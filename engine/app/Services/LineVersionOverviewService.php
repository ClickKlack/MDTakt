<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Enums\PeriodStatus;
use App\Models\LineVersion;
use App\Models\SchedulePeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stellt die Fahrplan-Änderungshistorie der laufenden Periode für die Admin-Ansicht zusammen
 * (FAHRPLANPERIODEN §5.4): je Linie und Fahrplantyp die Versionen mit ihren Intervallen.
 */
final class LineVersionOverviewService
{
    /**
     * @return array{period: SchedulePeriod|null, coverage: array<string, mixed>|null, lines: array<int, array<string, mixed>>}
     */
    public function overview(?string $line = null, ?FahrplanTyp $dayType = null): array
    {
        $periode = SchedulePeriod::query()->where('status', PeriodStatus::Current)->first();

        if ($periode === null) {
            return ['period' => null, 'coverage' => null, 'lines' => []];
        }

        $versionen = LineVersion::query()
            ->where('period_id', $periode->id)
            ->when($line !== null, fn ($q) => $q->where('line', $line))
            ->when($dayType !== null, fn ($q) => $q->where('day_type', $dayType->value))
            ->with(['intervals' => fn ($q) => $q->orderBy('valid_from')])
            ->get();

        $fahrtenzahlen = $this->tripCounts($versionen);

        $lines = $versionen
            ->groupBy('line')
            ->map(fn ($gruppe, string $line): array => [
                'line' => $line,
                'versions' => $gruppe
                    ->sortBy([['day_type', 'asc'], ['version_no', 'asc']])
                    ->map(fn (LineVersion $v): array => [
                        'id' => $v->id,
                        'line' => $v->line,
                        'day_type' => $v->day_type->value,
                        'day_type_label' => $v->day_type->label(),
                        'version_no' => $v->version_no,
                        'fingerprint' => $v->fingerprint,
                        'trip_count' => $fahrtenzahlen[$v->id] ?? null,
                        'first_seen_at' => $v->first_seen_at?->toIso8601String(),
                        'last_seen_at' => $v->last_seen_at?->toIso8601String(),
                        'intervals' => $v->intervals->map(fn ($i): array => [
                            'valid_from' => $i->valid_from->toDateString(),
                            'valid_to' => $i->valid_to->toDateString(),
                            'from_confirmed' => $i->from_confirmed,
                            'to_confirmed' => $i->to_confirmed,
                        ])->values()->all(),
                    ])->values()->all(),
            ])
            ->sortKeys()
            ->values()
            ->all();

        return [
            'period' => $periode,
            'coverage' => $this->coverage($versionen),
            'lines' => $lines,
        ];
    }

    /**
     * Fahrten je Version — aus dem Konsolidat.
     *
     * Bis Phase C wurde hier am Roh-Bestand gezählt, am ersten Tag des ersten Intervalls; lag
     * dieser Tag außerhalb des Feed-Fensters, blieb die Spalte leer. Das war damals ehrlich,
     * weil es die Fahrten schlicht nicht mehr gab — heute ist es falsch: Genau diese Historie
     * hält das Konsolidat, und eine Version von August erschien als „keine Fahrten", obwohl
     * ihre 393 Fahrten dauerhaft gespeichert sind.
     *
     * Leer bleibt die Angabe jetzt nur noch, wenn eine Version wirklich keinen Inhalt trägt —
     * dieselbe Aussage, die die Abdeckungs-Anzeige als `versions_without_content` führt.
     *
     * @param  Collection<int, LineVersion>  $versionen
     * @return array<int, int>
     */
    private function tripCounts(Collection $versionen): array
    {
        if ($versionen->isEmpty()) {
            return [];
        }

        return DB::table('consolidated_trips')
            ->whereIn('line_version_id', $versionen->pluck('id'))
            ->select('line_version_id', DB::raw('count(*) as anzahl'))
            ->groupBy('line_version_id')
            ->pluck('anzahl', 'line_version_id')
            ->map(static fn ($anzahl): int => (int) $anzahl)
            ->all();
    }

    /**
     * @param  Collection<int, LineVersion>  $versionen
     * @return array<string, mixed>|null
     */
    private function coverage(Collection $versionen): ?array
    {
        $intervalle = $versionen->flatMap->intervals;

        if ($intervalle->isEmpty()) {
            return null;
        }

        return [
            'from' => $intervalle->min('valid_from')?->toDateString(),
            'to' => $intervalle->max('valid_to')?->toDateString(),
            'confirmed_boundaries' => $intervalle->sum(fn ($i): int => (int) $i->from_confirmed + (int) $i->to_confirmed),
            'open_boundaries' => $intervalle->sum(fn ($i): int => (int) ! $i->from_confirmed + (int) ! $i->to_confirmed),
        ];
    }
}
