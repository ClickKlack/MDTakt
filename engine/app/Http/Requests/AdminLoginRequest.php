<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Validiert die Admin-Login-Anfrage (E-Mail + Passwort).
 */
final class AdminLoginRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
