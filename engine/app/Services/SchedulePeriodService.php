<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PeriodOrigin;
use App\Models\SchedulePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pflegt die netzweiten Fahrplanperioden (FAHRPLANPERIODEN §4.1).
 *
 * Perioden bilden eine **lückenlose, überschneidungsfreie Kette**: Maßgeblich ist allein
 * `valid_from`. `valid_to` ist daraus **abgeleitet** und wird nach jeder Änderung neu
 * gerechnet — der Vorgänger endet am Vortag des Nachfolgers, die jüngste Periode bleibt offen.
 *
 * Zwei Felder redundant zu halten wäre die Alternative gewesen; sie geht erfahrungsgemäß
 * beim ersten Verschieben einer Grenze auseinander. Deshalb hat nur `valid_from` einen
 * eigenen Wert.
 *
 * `status` hat gar keinen mehr: Er hängt zusätzlich am heutigen Tag und veraltete als Spalte
 * still, weil nur ein Schreibvorgang ihn nachzog. Er wird beim Lesen abgeleitet
 * ({@see SchedulePeriod::status()}).
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
     * `valid_from` gilt die neue Periode — ab diesem Tag, nicht ab dem Anlegen. Für sie
     * beginnt die Versions-Zählung jeder (Linie, Fahrplantyp) wieder bei 1 (§4.1) — das
     * ergibt sich von selbst, weil `version_no` je Periode gezählt wird.
     */
    public function create(string $label, string $validFrom): SchedulePeriod
    {
        return DB::transaction(function () use ($label, $validFrom): SchedulePeriod {
            $periode = SchedulePeriod::query()->create([
                'label' => $label,
                'valid_from' => $validFrom,
                'valid_to' => null,
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
     * Rechnet `valid_to` aus der Reihenfolge der `valid_from` neu: Jede Periode endet am
     * Vortag ihrer Nachfolgerin, die jüngste bleibt offen. Idempotent — mehrfaches
     * Ausführen ändert nichts.
     *
     * `status` steht hier bewusst nicht mehr: Er hängt am heutigen Tag, und ein Wert, den nur
     * ein Schreibvorgang nachzieht, wäre am nächsten Morgen falsch.
     */
    public function rebuildChain(): void
    {
        $perioden = SchedulePeriod::query()->orderBy('valid_from')->get();

        foreach ($perioden as $i => $periode) {
            $nachfolger = $perioden->get($i + 1);

            $periode->update([
                'valid_to' => $nachfolger === null
                    ? null
                    : CarbonImmutable::parse($nachfolger->valid_from->toDateString())->subDay()->toDateString(),
            ]);
        }
    }

    /**
     * Die heute geltende Periode, oder `null`, wenn die Kette erst in der Zukunft beginnt.
     */
    public function current(): ?SchedulePeriod
    {
        return SchedulePeriod::query()->current()->first();
    }
}
