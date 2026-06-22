<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CalendarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * GTFS calendar — reguläres Wochenmuster der Betriebstage je service_id.
 * Ausnahmen dazu stehen in calendar_dates.
 *
 * @property string $service_id
 * @property bool $monday
 * @property bool $tuesday
 * @property bool $wednesday
 * @property bool $thursday
 * @property bool $friday
 * @property bool $saturday
 * @property bool $sunday
 * @property Carbon $start_date
 * @property Carbon $end_date
 *
 * @use HasFactory<CalendarFactory>
 */
final class Calendar extends Model
{
    /** @use HasFactory<CalendarFactory> */
    use HasFactory;

    protected $table = 'calendar';

    protected $primaryKey = 'service_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'service_id',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
        'start_date',
        'end_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monday' => 'boolean',
            'tuesday' => 'boolean',
            'wednesday' => 'boolean',
            'thursday' => 'boolean',
            'friday' => 'boolean',
            'saturday' => 'boolean',
            'sunday' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }
}
