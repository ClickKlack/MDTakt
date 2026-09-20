<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FahrplanTyp;
use Illuminate\Validation\Rule;

/**
 * Anlegen und Ändern eines Umlaufs.
 *
 * Bewusst **ohne** Eindeutigkeitsregel auf `number`: Ob die Kursnummer netzweit eindeutig ist,
 * ist offen (KURSE §2 K3). Eine Dublette wird gemeldet, nicht abgewiesen.
 */
final class CourseRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $basis = [
            'number' => ['required', 'string', 'max:8'],
            'note' => ['nullable', 'string', 'max:255'],
        ];

        if ($this->isMethod('POST')) {
            return $basis + [
                'period_id' => ['required', 'integer', 'exists:schedule_periods,id'],
                'day_type' => ['required', Rule::enum(FahrplanTyp::class)],
            ];
        }

        return $basis;
    }
}
