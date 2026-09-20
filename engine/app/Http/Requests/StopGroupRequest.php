<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Anlegen und Umbenennen einer Haltestelle.
 */
final class StopGroupRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
