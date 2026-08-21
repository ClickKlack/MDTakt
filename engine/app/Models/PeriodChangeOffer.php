<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PeriodOfferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Systemvorschlag für einen Periodenwechsel (FAHRPLANPERIODEN §4.3).
 *
 * @property int $id
 * @property Carbon $suggested_from
 * @property int $changed_line_count
 * @property int $active_line_count
 * @property array<int, string> $lines
 * @property PeriodOfferStatus $status
 * @property Carbon|null $decided_at
 */
final class PeriodChangeOffer extends Model
{
    protected $fillable = [
        'suggested_from', 'changed_line_count', 'active_line_count', 'lines', 'status', 'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suggested_from' => 'date',
            'changed_line_count' => 'integer',
            'active_line_count' => 'integer',
            'lines' => 'array',
            'status' => PeriodOfferStatus::class,
            'decided_at' => 'datetime',
        ];
    }
}
