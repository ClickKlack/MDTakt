<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsolidatedTripFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine Fahrt des Konsolidats, identifiziert über ihre Signatur innerhalb einer Linien-Version.
 *
 * @property int $id
 * @property int $line_version_id
 * @property string $signature
 * @property int $route_type
 * @property int|null $first_stop_id
 * @property int|null $last_stop_id
 */
final class ConsolidatedTrip extends Model
{
    /** @use HasFactory<ConsolidatedTripFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['line_version_id', 'signature', 'route_type', 'first_stop_id', 'last_stop_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['route_type' => 'integer'];
    }

    /**
     * @return BelongsTo<LineVersion, $this>
     */
    public function lineVersion(): BelongsTo
    {
        return $this->belongsTo(LineVersion::class, 'line_version_id');
    }

    /**
     * @return HasMany<ConsolidatedStopTime, $this>
     */
    public function stopTimes(): HasMany
    {
        return $this->hasMany(ConsolidatedStopTime::class, 'consolidated_trip_id');
    }
}
