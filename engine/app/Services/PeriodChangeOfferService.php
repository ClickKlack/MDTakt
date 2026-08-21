<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PeriodOfferStatus;
use App\Models\LineVersion;
use App\Models\LineVersionInterval;
use App\Models\PeriodChangeOffer;
use App\Models\SchedulePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entscheidet über Periodenwechsel-Vorschläge (FAHRPLANPERIODEN §4.3).
 *
 * Das System schlägt vor, der Admin entscheidet. Nimmt er an, werden die Linien-Versionen ab
 * dem Wechseltag **zurückgenommen** und durch den Periodenwechsel ersetzt — jede (Linie,
 * Fahrplantyp) startet in der neuen Periode wieder bei Version 1. So bleibt die alte Periode
 * frei von Versions-Wildwuchs, der in Wahrheit ein einziger Fahrplanwechsel war.
 */
final class PeriodChangeOfferService
{
    public function __construct(
        private readonly SchedulePeriodService $periods,
        private readonly ScheduleVersionService $versions,
    ) {}

    /**
     * Offene Vorschläge, ältester Wechseltag zuerst.
     *
     * @return Collection<int, PeriodChangeOffer>
     */
    public function open(): Collection
    {
        return PeriodChangeOffer::query()
            ->where('status', PeriodOfferStatus::Open)
            ->orderBy('suggested_from')
            ->get();
    }

    /**
     * Nimmt den Vorschlag an: neue Periode ab dem Wechseltag, Versionen ab diesem Tag
     * zurückgenommen, danach frisch konsolidiert.
     */
    public function accept(PeriodChangeOffer $offer, string $label): SchedulePeriod
    {
        $wechseltag = $offer->suggested_from->toDateString();

        $periode = DB::transaction(function () use ($offer, $label, $wechseltag): SchedulePeriod {
            $periode = $this->periods->create($label, $wechseltag);
            $this->rollbackFrom($wechseltag);

            $offer->update([
                'status' => PeriodOfferStatus::Accepted,
                'decided_at' => CarbonImmutable::now(),
            ]);

            return $periode;
        });

        // Erst nach der Rücknahme neu aufbauen: Die Tage ab dem Wechseltag fallen jetzt in die
        // neue Periode und bekommen dort Version 1.
        $ergebnis = $this->versions->updateFromCurrentImport();

        Log::info('Period change offer accepted', [
            'offer_id' => $offer->id,
            'period_id' => $periode->id,
            'suggested_from' => $wechseltag,
            'versions_created' => $ergebnis['versions_created'],
        ]);

        return $periode->refresh();
    }

    public function decline(PeriodChangeOffer $offer): void
    {
        $offer->update([
            'status' => PeriodOfferStatus::Declined,
            'decided_at' => CarbonImmutable::now(),
        ]);

        // Die Änderungen bleiben als gewöhnliche Linien-Versionen in der laufenden Periode
        // stehen (§4.3) — abgelehnt heißt „war kein Fahrplanwechsel", nicht „war nichts".
        Log::info('Period change offer declined', [
            'offer_id' => $offer->id,
            'suggested_from' => $offer->suggested_from->toDateString(),
        ]);
    }

    /**
     * Nimmt alle beobachtete Gültigkeit ab dem Wechseltag zurück.
     *
     * Intervalle, die über den Tag hinweglaufen, werden am Vortag gekappt — ihre neue
     * Obergrenze ist ein exakt bekanntes Datum und damit gesichert. Versionen, die danach
     * keine Gültigkeit mehr tragen, verschwinden: Sie existierten allein wegen des Wechsels,
     * der jetzt als Periodengrenze geführt wird.
     */
    private function rollbackFrom(string $wechseltag): void
    {
        $vortag = CarbonImmutable::parse($wechseltag)->subDay()->toDateString();

        LineVersionInterval::query()
            ->whereDate('valid_from', '>=', $wechseltag)
            ->delete();

        LineVersionInterval::query()
            ->whereDate('valid_to', '>=', $wechseltag)
            ->update(['valid_to' => $vortag, 'to_confirmed' => true]);

        $verwaist = LineVersion::query()->whereDoesntHave('intervals')->get();

        foreach ($verwaist as $version) {
            $version->delete();
        }

        Log::debug('Line versions rolled back for period change', [
            'from' => $wechseltag,
            'versions_removed' => $verwaist->count(),
        ]);
    }
}
