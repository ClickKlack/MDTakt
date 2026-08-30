<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsolidatedStopVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Attribut-Historie eines Halts: Name und Lage für einen beobachteten Zeitraum.
 *
 * @property int $id
 * @property int $consolidated_stop_id
 * @property string $name
 * @property string $lat
 * @property string $lon
 * @property Carbon $valid_from
 * @property Carbon $valid_to
 * @property bool $from_confirmed
 * @property bool $to_confirmed
 */
final class ConsolidatedStopVersion extends Model
{
    /** @use HasFactory<ConsolidatedStopVersionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'consolidated_stop_id', 'name', 'lat', 'lon',
        'valid_from', 'valid_to', 'from_confirmed', 'to_confirmed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'from_confirmed' => 'boolean',
            'to_confirmed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ConsolidatedStop, $this>
     */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedStop::class, 'consolidated_stop_id');
    }
}
