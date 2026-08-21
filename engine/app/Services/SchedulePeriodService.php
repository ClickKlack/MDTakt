<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PeriodOrigin;
use App\Enums\PeriodStatus;
use App\Models\SchedulePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pflegt die netzweiten Fahrplanperioden (FAHRPLANPERIODEN §4.1).
 *
 * Perioden bilden eine **lückenlose, überschneidungsfreie Kette**: Maßgeblich ist allein
 * `valid_from`. `valid_to` und `status` sind daraus **abgeleitet** und werden nach jeder
 * Änderung neu gerechnet — der Vorgänger endet am Vortag des Nachfolgers, die jüngste
 * Periode bleibt offen, und `current` trägt die Periode, die den heutigen Tag abdeckt.
 *
 * Zwei Felder redundant zu halten wäre die Alternative gewesen; sie geht erfahrungsgemäß
 * beim ersten Verschieben einer Grenze auseinander. Deshalb hat nur `valid_from` einen
 * eigenen Wert.
 */
final class SchedulePeriodService
{
    /**
     * Alle Perioden, jüngste zuerst — mit der Zahl der daran hängenden Linien-Versionen.
     *
     * @return Collection<int, SchedulePeriod>
     */
    public function list(): Collection
    {
        return SchedulePeriod::query()
            ->withCount('lineVersions')
            ->orderByDesc('valid_from')
            ->get();
    }

    /**
     * Legt eine Periode an. Die Kette zieht nach: Der Vorgänger endet am Vortag, ab
     * `valid_from` gilt die neue Periode. Für sie beginnt die Versions-Zählung jeder
     * (Linie, Fahrplantyp) wieder bei 1 (§4.1) — das ergibt sich von selbst, weil
     * `version_no` je Periode gezählt wird.
     */
    public function create(string $label, string $validFrom): SchedulePeriod
    {
        return DB::transaction(function () use ($label, $validFrom): SchedulePeriod {
            $periode = SchedulePeriod::query()->create([
                'label' => $label,
                'valid_from' => $validFrom,
                'valid_to' => null,
                'status' => PeriodStatus::Frozen, // wird gleich neu bestimmt
                'created_via' => PeriodOrigin::Admin,
            ]);

            $this->rebuildChain();

            Log::info('Schedule period created', [
                'period_id' => $periode->id,
                'label' => $label,
                'valid_from' => $validFrom,
            ]);

            return $periode->refresh();
        });
    }

    /**
     * Ändert Label und Beginn. Beim Verschieben von `valid_from` rechnet die Kette neu —
     * die Gültigkeit der Nachbarperioden zieht mit.
     */
    public function update(SchedulePeriod $periode, string $label, string $validFrom): SchedulePeriod
    {
        return DB::transaction(function () use ($periode, $label, $validFrom): SchedulePeriod {
            $vorher = $periode->valid_from->toDateString();

            $periode->update(['label' => $label, 'valid_from' => $validFrom]);
            $this->rebuildChain();

            Log::info('Schedule period updated', [
                'period_id' => $periode->id,
                'valid_from_before' => $vorher,
                'valid_from_after' => $validFrom,
            ]);

            return $periode->refresh();
        });
    }

    /**
     * Löscht eine Periode. Der Aufrufer stellt sicher, dass keine Linien-Versionen daran
     * hängen — sonst risse das Löschen (per `cascadeOnDelete`) beobachtete Fahrplan-Historie
     * mit, und die ist nicht wiederbeschaffbar.
     */
    public function delete(SchedulePeriod $periode): void
    {
        DB::transaction(function () use ($periode): void {
            $id = $periode->id;
            $periode->delete();
            $this->rebuildChain();

            Log::info('Schedule period deleted', ['period_id' => $id]);
        });
    }

    /**
     * Die Periode, die ein Datum abdeckt: die jüngste, die nicht nach diesem Tag beginnt.
     * Liegt das Datum vor der ersten Periode, greift die erste — ein Import darf nicht
     * daran scheitern, dass sein Fenster weiter zurückreicht als die kuratierte Kette.
     */
    public function periodForDate(CarbonImmutable $datum): ?SchedulePeriod
    {
        $treffer = SchedulePeriod::query()
            ->whereDate('valid_from', '<=', $datum->toDateString())
            ->orderByDesc('valid_from')
            ->first();

        return $treffer ?? SchedulePeriod::query()->orderBy('valid_from')->first();
    }

    /**
     * Rechnet `valid_to` und `status` aus der Reihenfolge der `valid_from` neu.
     * Idempotent — mehrfaches Ausführen ändert nichts.
     */
    public function rebuildChain(): void
    {
        $perioden = SchedulePeriod::query()->orderBy('valid_from')->get();
        $heute = CarbonImmutable::now()->startOfDay();

        foreach ($perioden as $i => $periode) {
            $nachfolger = $perioden->get($i + 1);

            $validTo = $nachfolger === null
                ? null
                : CarbonImmutable::parse($nachfolger->valid_from->toDateString())->subDay()->toDateString();

            // `current` ist die Periode, die heute gilt: begonnen und noch nicht abgelöst.
            // Eine erst künftig beginnende Periode bleibt bewusst `frozen` — sonst würde der
            // nächste Import seine Versionen einer noch nicht geltenden Periode zuschlagen.
            $laeuft = ! CarbonImmutable::parse($periode->valid_from->toDateString())->greaterThan($heute)
                && ($validTo === null || ! CarbonImmutable::parse($validTo)->lessThan($heute));

            $periode->update([
                'valid_to' => $validTo,
                'status' => $laeuft ? PeriodStatus::Current : PeriodStatus::Frozen,
            ]);
        }
    }
}
