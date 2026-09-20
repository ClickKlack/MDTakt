<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\StopGroup;

/**
 * Legt eine Haltestelle in eine andere hinein — der Weg für „Rothensee (Schleife)" → „Rothensee".
 */
final class StopGroupMergeRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source_id' => ['required', 'integer', 'exists:stop_groups,id'],
        ];
    }

    public function source(): StopGroup
    {
        return StopGroup::query()->findOrFail($this->integer('source_id'));
    }
}
