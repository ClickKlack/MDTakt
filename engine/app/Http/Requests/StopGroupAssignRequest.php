<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Ordnet einen Halt einer Haltestelle zu.
 */
final class StopGroupAssignRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'consolidated_stop_id' => ['required', 'integer', 'exists:consolidated_stops,id'],
        ];
    }

    public function stopId(): int
    {
        return $this->integer('consolidated_stop_id');
    }
}
