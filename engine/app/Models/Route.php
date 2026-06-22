<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RouteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GTFS-Linie (route) der MVB — Tram oder Bus, je nach route_type.
 *
 * @property string $route_id
 * @property string $route_short_name
 * @property int $route_type
 *
 * @use HasFactory<RouteFactory>
 */
final class Route extends Model
{
    /** @use HasFactory<RouteFactory> */
    use HasFactory;

    protected $primaryKey = 'route_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'route_id',
        'route_short_name',
        'route_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'route_type' => 'integer',
        ];
    }

    /**
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'route_id', 'route_id');
    }
}
