<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ScheduleSource;
use Illuminate\Validation\Rule;

/**
 * Validiert die Quellenwahl der öffentlichen Fahrplan-Endpunkte.
 *
 * Vorgabe ist das **Konsolidat**: Es ist der dauerhafte Bestand und das, was der öffentliche
 * Viewer zeigen soll. Der Roh-Bestand bleibt über `?source=raw` erreichbar — die
 * Admin-Schaltzentrale schaltet damit auf den letzten Import um.
 */
final class ScheduleSourceRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source' => ['nullable', Rule::enum(ScheduleSource::class)],
        ];
    }

    public function source(): ScheduleSource
    {
        $wert = $this->query('source');

        return $wert === null
            ? ScheduleSource::Consolidated
            : ScheduleSource::from((string) $wert);
    }
}
