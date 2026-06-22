<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StopTimeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GTFS-Haltezeit (stop_time) — Ankunft/Abfahrt einer Fahrt an einer Haltestelle.
 *
 * Zeiten sind als String gespeichert, da GTFS Werte > 24:00:00 erlaubt
 * (Fahrten nach Mitternacht im selben Betriebstag).
 *
 * @property string $trip_id
 * @property string $stop_id
 * @property string|null $arrival_time
 * @property string|null $departure_time
 * @property int $stop_sequence
 *
 * @use HasFactory<StopTimeFactory>
 */
final class StopTime extends Model
{
    /** @use HasFactory<StopTimeFactory> */
    use HasFactory;

    // Zusammengesetzter Schlüssel (trip_id, stop_sequence) — kein einzelnes Auto-Increment.
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'trip_id',
        'stop_id',
        'arrival_time',
        'departure_time',
        'stop_sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stop_sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    /**
     * @return BelongsTo<Stop, $this>
     */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'stop_id', 'stop_id');
    }
}
