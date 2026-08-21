<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SchedulePeriod;
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
                'required',
                'date_format:Y-m-d',
                $this->tagNochFrei($periode instanceof SchedulePeriod ? $periode->id : null),
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

    public function label(): string
    {
        return (string) $this->validated('label');
    }

    public function validFrom(): string
    {
        return (string) $this->validated('valid_from');
    }
}
