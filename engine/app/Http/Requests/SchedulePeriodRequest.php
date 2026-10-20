<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SchedulePeriod;
use App\Services\PeriodResetService;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Validiert das Anlegen/Ändern einer Fahrplanperiode (FAHRPLANPERIODEN §4.1).
 *
 * `valid_to` ist bewusst **kein** Eingabefeld: Die Gültigkeit endet dort, wo die Folgeperiode
 * beginnt — SchedulePeriodService rechnet sie aus der Kette.
 */
final class SchedulePeriodRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $periode = $this->route('period');

        return [
            'label' => ['required', 'string', 'max:255'],
            'valid_from' => [
                'bail',
                'required',
                'date_format:Y-m-d',
                $this->tagNochFrei($periode instanceof SchedulePeriod ? $periode->id : null),
                $this->neuAufbaubar($periode instanceof SchedulePeriod ? $periode : null),
            ],
        ];
    }

    /**
     * Zwei Perioden am selben Tag ließen die Kette nicht mehr eindeutig ordnen.
     *
     * Bewusst `whereDate` statt `Rule::unique`: Der `date`-Cast schreibt den Wert als
     * `Y-m-d H:i:s`. PostgreSQL wirft die Uhrzeit beim Schreiben in die `date`-Spalte weg,
     * SQLite (Testlauf) behält sie als Text — ein Gleichheitsvergleich auf `Y-m-d` fände
     * dort nichts und die Regel liefe ins Leere.
     */
    private function tagNochFrei(?int $ignoriereId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoriereId): void {
            $belegt = DB::table('schedule_periods')
                ->whereDate('valid_from', $value)
                ->when($ignoriereId !== null, fn ($q) => $q->where('id', '!=', $ignoriereId))
                ->exists();

            if ($belegt) {
                $fail('An diesem Tag beginnt bereits eine Fahrplanperiode.');
            }
        };
    }

    /**
     * Eine neue Grenze setzt die Linien-Versionen ab dort zurück und baut sie aus dem aktuellen
     * Lauf neu auf (FAHRPLANPERIODEN §4.1). Was davor liegt, enthält der Lauf nicht mehr — eine
     * Grenze, hinter der schon ältere Beobachtungen stehen, risse sie unwiederbringlich mit.
     *
     * Beim Verschieben zählt das frühere der beiden Daten: Ab dort wechseln Tage die Periode.
     */
    private function neuAufbaubar(?SchedulePeriod $periode): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($periode): void {
            $vorher = $periode?->valid_from->toDateString();

            if (! is_string($value) || $vorher === $value) {
                return;
            }

            $ab = $vorher === null ? $value : min($vorher, $value);

            if (! app(PeriodResetService::class)->canResetFrom($ab)) {
                $fail('Ab diesem Tag liegen bereits Beobachtungen vor dem aktuellen Feed-Fenster. Sie ließen sich nach dem Zurücksetzen nicht neu aufbauen.');
            }
        };
    }

    public function label(): string
    {
        return (string) $this->validated('label');
    }

    public function validFrom(): string
    {
        return (string) $this->validated('valid_from');
    }
}
