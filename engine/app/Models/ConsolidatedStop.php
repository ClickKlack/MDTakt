<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsolidatedStopFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ein physischer Halt des Konsolidats (FAHRPLANPERIODEN §6).
 *
 * @property int $id
 * @property string $anchor_lat
 * @property string $anchor_lon
 * @property string $name_key
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
final class ConsolidatedStop extends Model
{
    /** @use HasFactory<ConsolidatedStopFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['anchor_lat', 'anchor_lon', 'name_key', 'first_seen_at', 'last_seen_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ConsolidatedStopVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ConsolidatedStopVersion::class, 'consolidated_stop_id');
    }
}
