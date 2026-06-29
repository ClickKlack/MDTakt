<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Validiert das Setzen einer Linienfarbe (Hex inkl. '#').
 */
final class LineColorUpdateRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
