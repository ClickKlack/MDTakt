<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MdktRouteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Laufweg einer Fahrt aus MDKursTracker, geschlüsselt auf dessen `schedule_fingerprint`.
 *
 * Die Zeiten stammen vom Tag, an dem der Tracker den Laufweg erfasst hat, nicht vom Tag der
 * Sichtung. Für die Zuordnung zählt nur die lokale Uhrzeit je Halt (INTEGRATION_MDKURSTRACKER §4.1).
 *
 * @property int $id
 * @property string $fingerprint
 * @property int|null $mdkt_trip_id
 * @property string $line
 * @property string|null $direction
 * @property array<int, array{seq: int, hafas_stop_id: string, stop_name: ?string, line: string, arrival_planned: ?string, departure_planned: ?string}> $stops
 */
final class MdktRoute extends Model
{
    /** @use HasFactory<MdktRouteFactory> */
    use HasFactory;

    protected $fillable = ['fingerprint', 'mdkt_trip_id', 'line', 'direction', 'stops'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mdkt_trip_id' => 'integer',
            'stops' => 'array',
        ];
    }

    /**
     * @return HasMany<Sighting, $this>
     */
    public function sightings(): HasMany
    {
        return $this->hasMany(Sighting::class, 'mdkt_route_id');
    }
}
