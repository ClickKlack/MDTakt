<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SchoolHolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Schulferien-Zeitraum (Sachsen-Anhalt), vom Admin gepflegt.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 */
final class SchoolHoliday extends Model
{
    /** @use HasFactory<SchoolHolidayFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }
}
