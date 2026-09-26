<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\SightingReviewService;
use Illuminate\Validation\Rule;

/**
 * Filter der Prüfliste. Ohne Angabe zeigt sie die Warteschlange (`state=open`).
 */
final class SightingFilterRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleans('differs_only');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state' => ['sometimes', Rule::in(SightingReviewService::STATES)],
            'line' => ['sometimes', 'nullable', 'string', 'max:8'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'differs_only' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
