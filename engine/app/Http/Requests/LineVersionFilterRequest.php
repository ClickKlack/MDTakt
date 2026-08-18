<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FahrplanTyp;
use Illuminate\Validation\Rule;

/**
 * Validiert die optionalen Filter von GET /api/v1/admin/line-versions.
 */
final class LineVersionFilterRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'line' => ['nullable', 'string', 'max:255'],
            'day_type' => ['nullable', Rule::enum(FahrplanTyp::class)],
        ];
    }

    public function line(): ?string
    {
        $value = $this->query('line');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function dayType(): ?FahrplanTyp
    {
        $value = $this->query('day_type');

        return is_string($value) && $value !== '' ? FahrplanTyp::from($value) : null;
    }
}
