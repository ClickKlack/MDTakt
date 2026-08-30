<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsolidatedStopTimeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Halt einer Konsolidat-Fahrt. Zeiten bleiben GTFS-Lokalzeit als Text (auch > 24:00).
 *
 * @property int $id
 * @property int $consolidated_trip_id
 * @property int $stop_id
 * @property int $stop_sequence
 * @property string|null $arrival_time
 * @property string|null $departure_time
 */
final class ConsolidatedStopTime extends Model
{
    /** @use HasFactory<ConsolidatedStopTimeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'consolidated_trip_id', 'stop_id', 'stop_sequence', 'arrival_time', 'departure_time',
    ];

    /**
     * @return BelongsTo<ConsolidatedTrip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedTrip::class, 'consolidated_trip_id');
    }

    /**
     * @return BelongsTo<ConsolidatedStop, $this>
     */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedStop::class, 'stop_id');
    }
}
