<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Basis-FormRequest, der Validierungsfehler im einheitlichen API-Fehlerformat
 * { "error": { "code", "message" } } ausgibt. Autorisierung läuft über Middleware.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ja/Nein-Felder aus einer Query-Zeichenkette in echte Booleans umschreiben.
     *
     * Eine Query kennt keine Typen: `?include_terminals=false` kommt als **String** „false" an,
     * und Laravels `boolean`-Regel lässt nur `true`, `false`, `1`, `0`, `"1"` und `"0"` durch.
     * Ein per axios gesendetes `false` scheiterte deshalb mit 422, obwohl dasselbe Feld im
     * JSON-Rumpf desselben Endpunkts anstandslos durchgeht.
     *
     * Unlesbares bleibt unangetastet — dann soll die Regel den Fehler melden und nicht diese
     * Umschreibung ihn still zu `false` glätten.
     */
    protected function normalizeBooleans(string ...$felder): void
    {
        foreach ($felder as $feld) {
            $wert = $this->input($feld);

            if (! is_string($wert) || $wert === '') {
                continue;
            }

            $bool = filter_var($wert, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($bool !== null) {
                $this->merge([$feld => $bool]);
            }
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'error' => [
                    'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'message' => (string) $validator->errors()->first(),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}
