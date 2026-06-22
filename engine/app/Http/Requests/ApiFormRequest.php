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
