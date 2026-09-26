<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Kursauskunft für eine Abfahrt (Query) oder eine ganze Abfahrtstafel (JSON-Rumpf, `departures`).
 *
 * `date` ist informativ: Den Betriebstag bestimmt die Engine selbst aus der Soll-Zeit — mit derselben
 * Regel wie beim Sichtungs-Eingang, damit beide Richtungen dieselbe Fahrt meinen.
 */
final class CourseLookupRequest extends ApiFormRequest
{
    public const MAX_DEPARTURES = 100;

    private const UTC = 'date_format:Y-m-d\TH:i:s\Z';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return self::departureRules('');
        }

        return [
            'departures' => ['required', 'array', 'min:1', 'max:'.self::MAX_DEPARTURES],
            'departures.*.ref' => ['nullable', 'string', 'max:64'],
            ...self::departureRules('departures.*.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function departureRules(string $praefix): array
    {
        return [
            $praefix.'hafas_stop' => ['required', 'string', 'max:32'],
            $praefix.'line' => ['required', 'string', 'max:8'],
            $praefix.'time' => ['required', self::UTC],
            $praefix.'date' => ['nullable', 'date_format:Y-m-d'],
            $praefix.'stop_name' => ['nullable', 'string', 'max:255'],
            // Richtung/Ziel wie auf der Abfahrtstafel — trennt zwei Richtungen zur selben Minute
            $praefix.'direction' => ['nullable', 'string', 'max:255'],
        ];
    }
}
