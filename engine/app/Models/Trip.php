<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GTFS-Fahrt (trip) — eine einzelne Linienfahrt von A nach B.
 *
 * @property string $trip_id
 * @property string $route_id
 * @property string $service_id
 * @property string|null $block_id
 * @property int|null $direction_id
 *
 * @use HasFactory<TripFactory>
 */
final class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory;

    protected $primaryKey = 'trip_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'trip_id',
        'route_id',
        'service_id',
        'block_id',
        'direction_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    /**
     * @return HasMany<StopTime, $this>
     */
    public function stopTimes(): HasMany
    {
        return $this->hasMany(StopTime::class, 'trip_id', 'trip_id');
    }
}
