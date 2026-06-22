<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CalendarDateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * GTFS calendar_dates — Ausnahme zum Wochenmuster eines service_id.
 * exception_type: 1 = Betrieb hinzugefügt, 2 = Betrieb entfernt.
 *
 * @property string $service_id
 * @property Carbon $date
 * @property int $exception_type
 *
 * @use HasFactory<CalendarDateFactory>
 */
final class CalendarDate extends Model
{
    /** @use HasFactory<CalendarDateFactory> */
    use HasFactory;

    protected $table = 'calendar_dates';

    // Zusammengesetzter Schlüssel (service_id, date) — kein einzelnes Auto-Increment.
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'service_id',
        'date',
        'exception_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'exception_type' => 'integer',
        ];
    }
}
