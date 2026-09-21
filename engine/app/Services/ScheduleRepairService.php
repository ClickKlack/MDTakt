<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PeriodOfferStatus;
use App\Models\LineVersion;
use App\Models\LineVersionInterval;
use App\Models\PeriodChangeOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Räumt Beobachtungen weg, die nach heutiger Regel nie entstanden wären
 * (FAHRPLANPERIODEN §4.3, §10).
 *
 * Bis zum 21.09.2026 wertete die Konsolidierung den **letzten Tag des Feed-Fensters** aus,
 * obwohl dessen Betriebstag erst in der Nacht auf den Folgetag endet — und die liegt außerhalb.
 * Auf jeder Nachtlinie fehlten dadurch 13 von 18 Fahrten, sechzehn Linien bekamen einen neuen
 * Fingerprint, und das System bot einen Periodenwechsel an, den es nie gegeben hat. Dieselbe
 * Schwelle riss ein Feiertag, an dem zehn Linien für genau einen Tag abwichen.
 *
 * Die Regeln sind korrigiert; die Daten davor nicht. Dieser Dienst holt das nach — einmal je
 * Bestand, in dem die alten Läufe Spuren hinterlassen haben.
 *
 * **Was er anfasst, ist ausschließlich Beobachtung, nie Pflege.** Gelöscht wird nur eine
 * Version, die nach dem Eingriff keine Gültigkeit mehr trägt — an einer solchen hängen keine
 * Tage und damit auch keine Kurse, die jemand gepflegt hätte. Vorschläge werden **gelöscht,
 * nicht abgelehnt**: „abgelehnt" hieße, der Tag sei geprüft und verworfen, und ein späterer
 * Import dürfte ihn nie wieder vorschlagen. Er war aber nie geprüft — er war nie beobachtet.
 */
final class ScheduleRepairService
{
    public function __construct(private readonly ServiceDayResolver $serviceDays) {}

    /**
     * Ohne `$day` gilt der letzte Tag des aktuellen Feed-Fensters. Ein Bestand, dessen Feed
     * seither ausgetauscht wurde, braucht den Tag ausdruecklich — der alte Fensterrand liegt
     * dann im Inneren des neuen Fensters und ist von aussen nicht mehr zu erkennen.
     *
     * @return array{day: string|null, intervals_removed: int, versions_removed: int, boundaries_reopened: int, offers_withdrawn: array<int, string>, dry_run: bool}
     */
    public function repair(?string $day = null, bool $dryRun = false): array
    {
        $fenster = $this->serviceDays->feedWindow();
        $tag = $day ?? ($fenster === null ? null : $fenster['to']->toDateString());

        $bericht = [
            'day' => $tag,
            'intervals_removed' => 0,
            'versions_removed' => 0,
            'boundaries_reopened' => 0,
            'offers_withdrawn' => [],
            'dry_run' => $dryRun,
        ];

        // Der Probelauf soll sehen, was ein echter Lauf bewirkt — das geht nur, indem er es
        // tut und anschliessend verwirft. Deshalb die Transaktion von Hand statt per
        // `DB::transaction()`, dessen Abschluss nicht vom Ergebnis abhaengen kann.
        DB::beginTransaction();

        try {
            if ($tag !== null) {
                $this->removeHalfObservedDay($tag, $dryRun, $bericht);
            }

            $bericht['offers_withdrawn'] = $this->withdrawUnfoundedOffers($dryRun);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error('Schedule repair failed', ['day' => $tag, 'message' => $e->getMessage()]);

            throw $e;
        }

        Log::info($dryRun ? 'Schedule repair simulated' : 'Schedule repair applied', [
            'day' => $bericht['day'],
            'intervals_removed' => $bericht['intervals_removed'],
            'versions_removed' => $bericht['versions_removed'],
            'boundaries_reopened' => $bericht['boundaries_reopened'],
            'offers_withdrawn' => $bericht['offers_withdrawn'],
        ]);

        return $bericht;
    }

    /**
     * Nimmt die Beobachtung eines halb gesehenen Tages zurück.
     *
     * Drei Schritte, in dieser Reihenfolge: die an diesem Tag beginnenden Intervalle löschen,
     * die dadurch verwaisten Versionen löschen, und auf dem Vortag die Bestätigung
     * zurücknehmen. Der dritte ist der leicht zu übersehende: Das Intervall davor gilt als
     * **gesichert** beendet, weil dahinter ein anderer Fahrplan gesehen wurde — den es nicht
     * gab. Ohne ihn ist sein Ende wieder eine bloße Untergrenze (§5.4 b).
     *
     * @param  array{intervals_removed: int, versions_removed: int, boundaries_reopened: int, ...}  $bericht
     */
    private function removeHalfObservedDay(string $tag, bool $dryRun, array &$bericht): void
    {
        $phantome = LineVersionInterval::query()->whereDate('valid_from', $tag)->get();

        if ($phantome->isEmpty()) {
            return;
        }

        $betroffeneVersionen = $phantome->pluck('line_version_id')->unique();
        $vortag = CarbonImmutable::parse($tag)->subDay()->toDateString();

        // Die Stränge merken, bevor die Versionen verschwinden — die Bestätigung des Vortags
        // hängt am Strang, nicht an der Phantom-Version.
        $straenge = LineVersion::query()
            ->whereIn('id', $betroffeneVersionen)
            ->get(['period_id', 'line', 'day_type']);

        $bericht['intervals_removed'] = $phantome->count();
        LineVersionInterval::query()->whereKey($phantome->pluck('id'))->delete();

        $verwaist = LineVersion::query()
            ->whereIn('id', $betroffeneVersionen)
            ->whereDoesntHave('intervals')
            ->get();

        $bericht['versions_removed'] = $verwaist->count();

        foreach ($verwaist as $version) {
            $version->delete();
        }

        foreach ($straenge as $strang) {
            $bericht['boundaries_reopened'] += LineVersionInterval::query()
                ->whereDate('valid_to', $vortag)
                ->where('to_confirmed', true)
                ->whereIn('line_version_id', LineVersion::query()
                    ->select('id')
                    ->where('period_id', $strang->period_id)
                    ->where('line', $strang->line)
                    ->where('day_type', $strang->day_type))
                ->update(['to_confirmed' => false]);
        }

        Log::info($dryRun ? 'Half observed day would be withdrawn' : 'Half observed day withdrawn', [
            'day' => $tag,
            'intervals_removed' => $bericht['intervals_removed'],
            'versions_removed' => $bericht['versions_removed'],
            'boundaries_reopened' => $bericht['boundaries_reopened'],
        ]);
    }

    /**
     * Zieht offene Vorschläge zurück, die der beobachtete Bestand nicht mehr trägt.
     *
     * Zwei Gründe zählen, beide am gespeicherten Bestand prüfbar:
     *
     * a) **Keine Linie des Vorschlags wechselt an dem Tag** — etwa weil der Wechsel allein auf
     *    dem halb gesehenen Fenstertag ruhte, der soeben weggefallen ist.
     * b) **Jede wechselnde Linie kehrt zurück.** Liegt vor und hinter dem Abschnitt dieselbe
     *    Version, war er eine Abweichung und kein Wechsel (§4.3). In der Fingerprint-Rechnung
     *    der Konsolidierung ist das dieselbe Aussage: Eine Version *ist* ein Fingerprint.
     *
     * Beschiedene Vorschläge bleiben unberührt — in ihnen steckt eine Entscheidung des Admins.
     *
     * @return array<int, string> zurückgezogene Wechseltage
     */
    private function withdrawUnfoundedOffers(bool $dryRun): array
    {
        $zurueckgezogen = [];

        foreach (PeriodChangeOffer::query()->where('status', PeriodOfferStatus::Open)->get() as $vorschlag) {
            $tag = $vorschlag->suggested_from->toDateString();
            $wechselnde = $this->confirmedChangesOn($tag, $vorschlag->lines);

            if ($wechselnde->isNotEmpty() && $wechselnde->contains(fn (LineVersionInterval $i): bool => ! $this->isReverted($i))) {
                continue;
            }

            $grund = $wechselnde->isEmpty() ? 'no observed change' : 'deviation reverts to the previous schedule';

            Log::info($dryRun ? 'Period change offer would be withdrawn' : 'Period change offer withdrawn', [
                'offer_id' => $vorschlag->id,
                'suggested_from' => $tag,
                'reason' => $grund,
            ]);

            $vorschlag->delete();
            $zurueckgezogen[] = $tag;
        }

        return $zurueckgezogen;
    }

    /**
     * Die an diesem Tag beginnenden, **gesicherten** Abschnitte der genannten Linien — also
     * genau das, was die Konsolidierung als Wechseltag gezählt hätte.
     *
     * @param  array<int, string>  $linien
     * @return Collection<int, LineVersionInterval>
     */
    private function confirmedChangesOn(string $tag, array $linien): Collection
    {
        return LineVersionInterval::query()
            ->whereDate('valid_from', $tag)
            ->where('from_confirmed', true)
            ->whereIn('line_version_id', LineVersion::query()->select('id')->whereIn('line', $linien))
            ->get();
    }

    /**
     * Kehrt hinter diesem Abschnitt der vorherige Fahrplan zurück?
     *
     * Geprüft wird am Strang (Periode, Linie, Fahrplantyp): Gehören der Nachbar davor und der
     * Nachbar danach **derselben Version** an, war der Abschnitt dazwischen eine Abweichung.
     * Fehlt einer der beiden, ist die Rückkehr nicht beobachtet — dann bleibt es ein Wechsel.
     */
    private function isReverted(LineVersionInterval $abschnitt): bool
    {
        $version = $abschnitt->lineVersion;

        $strang = LineVersionInterval::query()
            ->whereIn('line_version_id', LineVersion::query()
                ->select('id')
                ->where('period_id', $version->period_id)
                ->where('line', $version->line)
                ->where('day_type', $version->day_type));

        $davor = (clone $strang)
            ->whereDate('valid_to', '<', $abschnitt->valid_from->toDateString())
            ->orderByDesc('valid_to')
            ->first();

        $danach = (clone $strang)
            ->whereDate('valid_from', '>', $abschnitt->valid_to->toDateString())
            ->orderBy('valid_from')
            ->first();

        return $davor !== null
            && $danach !== null
            && $davor->line_version_id === $danach->line_version_id;
    }
}
