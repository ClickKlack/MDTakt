<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PeriodOfferStatus;
use App\Models\LineVersion;
use App\Models\LineVersionInterval;
use App\Models\PeriodChangeOffer;
use App\Models\SchedulePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Setzt die Linien-Versionen an einer neuen Periodengrenze zurück (FAHRPLANPERIODEN §4.1/§4.3).
 *
 * Eine neue Periode **setzt alle Linien zurück** — gleich, ob der Admin sie von Hand anlegt,
 * ihren Beginn verschiebt oder einen Systemvorschlag annimmt. Die Kette umzustellen genügt
 * dafür nicht: Die bereits beobachteten Tage ab der Grenze hängen sonst weiter an den Versionen
 * der Vorperiode, und die neue Periode bleibt leer. Deshalb wird ab der Grenze zurückgenommen
 * und aus dem aktuellen Lauf neu aufgebaut.
 *
 * Neu aufbauen lässt sich nur, was der aktuelle Lauf noch enthält. Ältere Beobachtungen sind
 * nicht wiederbeschaffbar — eine Grenze vor dem Feed-Fenster, hinter der schon Gültigkeit
 * steht, wird deshalb abgewiesen ({@see canResetFrom()}).
 */
final class PeriodResetService
{
    public function __construct(
        private readonly SchedulePeriodService $periods,
        private readonly ScheduleVersionService $versions,
        private readonly ServiceDayResolver $serviceDays,
    ) {}

    /**
     * Legt eine Periode an und baut die Versionen ab ihrem Beginn neu auf.
     */
    public function create(string $label, string $validFrom): SchedulePeriod
    {
        $periode = DB::transaction(function () use ($label, $validFrom): SchedulePeriod {
            $periode = $this->periods->create($label, $validFrom);
            $this->rollbackFrom($validFrom);

            return $periode;
        });

        $this->rebuildFrom($validFrom, $periode);

        return $periode->refresh();
    }

    /**
     * Ändert Label und Beginn. Verschiebt sich der Beginn, wechseln die Tage zwischen altem und
     * neuem Datum die Periode — zurückgenommen wird deshalb ab dem früheren der beiden.
     */
    public function update(SchedulePeriod $periode, string $label, string $validFrom): SchedulePeriod
    {
        $vorher = $periode->valid_from->toDateString();

        if ($vorher === $validFrom) {
            return $this->periods->update($periode, $label, $validFrom);
        }

        $ab = min($vorher, $validFrom);

        DB::transaction(function () use ($periode, $label, $validFrom, $ab): void {
            $this->periods->update($periode, $label, $validFrom);
            $this->rollbackFrom($ab);
        });

        $this->rebuildFrom($ab, $periode);

        return $periode->refresh();
    }

    /**
     * Darf ab diesem Tag zurückgenommen werden? Nur, wenn alles, was dabei fällt, aus dem
     * aktuellen Lauf wieder entsteht: Die Grenze liegt im Feed-Fenster oder danach, oder vor
     * ihr steht ohnehin keine beobachtete Gültigkeit, die mit fiele.
     */
    public function canResetFrom(string $tag): bool
    {
        $window = $this->serviceDays->feedWindow();

        if ($window !== null && $tag >= $window['from']->toDateString()) {
            return true;
        }

        // Vor dem Fenster (oder ganz ohne Lauf) geht verloren, was ab dem Tag beobachtet ist.
        $grenze = $window === null ? null : $window['from']->toDateString();

        return ! LineVersionInterval::query()
            ->whereDate('valid_to', '>=', $tag)
            ->when($grenze !== null, fn ($q) => $q->whereDate('valid_from', '<', $grenze))
            ->exists();
    }

    /**
     * Nimmt alle beobachtete Gültigkeit ab dem Wechseltag zurück.
     *
     * Intervalle, die über den Tag hinweglaufen, werden am Vortag gekappt — ihre neue
     * Obergrenze ist ein exakt bekanntes Datum und damit gesichert. Versionen, die danach
     * keine Gültigkeit mehr tragen, verschwinden: Sie existierten allein wegen des Wechsels,
     * der jetzt als Periodengrenze geführt wird.
     *
     * Entscheidend ist dabei das **allein**: Gelöscht wird nur, was dieser Rollback selbst
     * verwaist hat. Eine Version kann ihre Gültigkeit auch aus anderem Grund verloren haben —
     * etwa weil ein späterer Lauf sie aus allen ihren Tagen verdrängt hat (§5.3). Die hält
     * fest, was der Feed einmal behauptet hat, und bleibt erhalten; über `line_versions`
     * hinge sonst ein `cascadeOnDelete` auf den konsolidierten Fahrten daran.
     */
    public function rollbackFrom(string $wechseltag): void
    {
        $vortag = CarbonImmutable::parse($wechseltag)->subDay()->toDateString();

        // Vor dem Eingriff merken, wessen Gültigkeit überhaupt berührt wird. Ein Intervall, das
        // am Wechseltag oder später beginnt, endet auch dort oder später — diese eine Bedingung
        // deckt beide Fälle ab.
        $beruehrt = LineVersionInterval::query()
            ->whereDate('valid_to', '>=', $wechseltag)
            ->pluck('line_version_id')
            ->unique()
            ->all();

        LineVersionInterval::query()
            ->whereDate('valid_from', '>=', $wechseltag)
            ->delete();

        LineVersionInterval::query()
            ->whereDate('valid_to', '>=', $wechseltag)
            ->update(['valid_to' => $vortag, 'to_confirmed' => true]);

        $verwaist = LineVersion::query()
            ->whereIn('id', $beruehrt)
            ->whereDoesntHave('intervals')
            ->get();

        foreach ($verwaist as $version) {
            $version->delete();
        }

        Log::debug('Line versions rolled back for period change', [
            'from' => $wechseltag,
            'versions_removed' => $verwaist->count(),
        ]);
    }

    /**
     * Erst nach der Rücknahme neu aufbauen: Die Tage ab der Grenze fallen jetzt in die neue
     * Periode und bekommen dort Version 1.
     *
     * Danach sind offene Vorschläge ab der Grenze neu zu bewerten. Alles ab dort ist frisch
     * aufgebaut, der Lauf hat also jeden Wechsel darin neu gesehen. Was er nicht mehr als
     * Wechsel wertet, hat die Grenze erledigt — so wie die Wochenend-Vorschläge 07./08.11.2026
     * hinter der Periode ab 02.11. Davor lässt sich nichts sagen: Dort standen die Versionen
     * schon und gelten dem Lauf deshalb nicht als neu.
     *
     * @return array{signatures: int, versions_created: int, intervals_written: int, lines_changed: int, consolidated_stops: int, consolidated_trips: int}
     */
    public function rebuildFrom(string $ab, SchedulePeriod $periode): array
    {
        $ergebnis = $this->versions->updateFromCurrentImport();
        $wechsel = $this->versions->changeDaysOfLastRun();

        $erledigt = PeriodChangeOffer::query()
            ->where('status', PeriodOfferStatus::Open)
            ->whereDate('suggested_from', '>=', $ab)
            ->get()
            ->reject(fn (PeriodChangeOffer $o): bool => in_array($o->suggested_from->toDateString(), $wechsel, true));

        foreach ($erledigt as $vorschlag) {
            $vorschlag->update([
                'status' => PeriodOfferStatus::Superseded,
                'decided_at' => CarbonImmutable::now(),
            ]);

            Log::info('Period change offer superseded by period', [
                'offer_id' => $vorschlag->id,
                'suggested_from' => $vorschlag->suggested_from->toDateString(),
                'period_id' => $periode->id,
            ]);
        }

        Log::info('Line versions rebuilt after period change', [
            'period_id' => $periode->id,
            'from' => $ab,
            'versions_created' => $ergebnis['versions_created'],
            'offers_superseded' => $erledigt->count(),
        ]);

        return $ergebnis;
    }
}
