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
     * Offene Vorschläge, ältester Wechseltag zuerst — angereichert um die Frage, wie gut der
     * vorgeschlagene Wechsel eigentlich belegt ist.
     *
     * @return Collection<int, PeriodChangeOffer>
     */
    public function open(): Collection
    {
        $vorschlaege = PeriodChangeOffer::query()
            ->where('status', PeriodOfferStatus::Open)
            ->orderBy('suggested_from')
            ->get();

        foreach ($vorschlaege as $vorschlag) {
            $this->addEvidence($vorschlag);
        }

        return $vorschlaege;
    }

    /**
     * Wie weit reicht die Beobachtung hinter dem Wechseltag?
     *
     * Fällt ein Wechsel auf den **Rand des beobachteten Fensters**, ist er durch genau einen Tag
     * belegt — und ein Fensterrand ist kein Fahrplanwechsel (FAHRPLANPERIODEN §5.4 b). Der erste
     * Folge-Import am 23.08.2026 zeigte das an einem realen Fall: 15 Linien änderten sich zum
     * 21.09., dem letzten Tag des Fensters. Ob das ein echter Fahrplanwechsel ist oder ein
     * Randeffekt, entscheidet erst der nächste Lauf.
     *
     * Gezählt wird allein die Gültigkeit der **im Vorschlag gelisteten** Linien. Über alle
     * Intervalle des Tages gerechnet, trug eine beliebige andere Linie die Kennzahl: Der
     * Vorschlag zum 03.10.2026 meldete „beobachtet bis 11.10." und galt damit als gut belegt,
     * obwohl jede seiner zehn Linien dort eine Eintagsversion hatte — die acht Tage stammten
     * von Linie 9, die gar nicht zum Vorschlag gehörte.
     *
     * Bewusst zur Lesezeit berechnet statt beim Anlegen gespeichert: Deckt ein späterer Import
     * den Zeitraum hinter dem Wechseltag ab, verschwindet der Hinweis von selbst.
     */
    private function addEvidence(PeriodChangeOffer $vorschlag): void
    {
        $tag = $vorschlag->suggested_from->toDateString();

        $beobachtetBis = LineVersionInterval::query()
            ->whereDate('valid_from', $tag)
            ->whereIn(
                'line_version_id',
                LineVersion::query()->select('id')->whereIn('line', $vorschlag->lines),
            )
            ->max('valid_to');

        $bis = $beobachtetBis === null ? $tag : CarbonImmutable::parse($beobachtetBis)->toDateString();

        $vorschlag->setAttribute('observed_until', $bis);
        $vorschlag->setAttribute('single_day_observation', $bis === $tag);
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
     *
     * Entscheidend ist dabei das **allein**: Gelöscht wird nur, was dieser Rollback selbst
     * verwaist hat. Eine Version kann ihre Gültigkeit auch aus anderem Grund verloren haben —
     * etwa weil ein späterer Lauf sie aus allen ihren Tagen verdrängt hat (§5.3). Die hält
     * fest, was der Feed einmal behauptet hat, und bleibt erhalten; über `line_versions`
     * hinge sonst ein `cascadeOnDelete` auf den konsolidierten Fahrten daran.
     */
    private function rollbackFrom(string $wechseltag): void
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
}
