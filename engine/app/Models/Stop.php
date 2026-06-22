<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StopFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GTFS-Haltestelle (stop) mit optionaler Geo-Position.
 *
 * @property string $stop_id
 * @property string $stop_name
 * @property float|null $lat
 * @property float|null $lon
 *
 * @use HasFactory<StopFactory>
 */
final class Stop extends Model
{
    /** @use HasFactory<StopFactory> */
    use HasFactory;

    protected $primaryKey = 'stop_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'stop_id',
        'stop_name',
        'lat',
        'lon',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lon' => 'float',
        ];
    }

    /**
     * @return HasMany<StopTime, $this>
     */
    public function stopTimes(): HasMany
    {
        return $this->hasMany(StopTime::class, 'stop_id', 'stop_id');
    }
}
