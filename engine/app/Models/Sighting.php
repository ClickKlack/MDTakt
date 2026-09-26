<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SightingMatch;
use App\Enums\SightingStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SightingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Sichtung aus MDKursTracker: „An Halt X fuhr Linie L mit Kurs K".
 *
 * Der Vergleich mit dem lokal gepflegten Kurs wird **nicht** gespeichert, sondern bei jeder
 * Abfrage berechnet — sonst veraltete er mit jeder Pflege im Fahrplan.
 *
 * @property int $id
 * @property int $mdkt_recording_id
 * @property int $mdkt_route_id
 * @property string $line
 * @property string $course_number
 * @property string $hafas_stop_id
 * @property string|null $stop_name
 * @property CarbonImmutable $service_date
 * @property CarbonImmutable $observed_at
 * @property CarbonImmutable $departure_planned
 * @property CarbonImmutable|null $departure_actual
 * @property string|null $trip_signature
 * @property int|null $consolidated_trip_id
 * @property SightingMatch $match
 * @property int $match_attempts
 * @property SightingStatus $status
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 */
final class Sighting extends Model
{
    /** @use HasFactory<SightingFactory> */
    use HasFactory;

    protected $fillable = [
        'mdkt_recording_id', 'mdkt_route_id', 'line', 'course_number', 'hafas_stop_id', 'stop_name',
        'service_date', 'observed_at', 'departure_planned', 'departure_actual',
        'trip_signature', 'consolidated_trip_id', 'match', 'match_attempts',
        'status', 'decided_at', 'decision_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mdkt_recording_id' => 'integer',
            'service_date' => 'immutable_date',
            'observed_at' => 'immutable_datetime',
            'departure_planned' => 'immutable_datetime',
            'departure_actual' => 'immutable_datetime',
            'consolidated_trip_id' => 'integer',
            'match' => SightingMatch::class,
            'match_attempts' => 'integer',
            'status' => SightingStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MdktRoute, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(MdktRoute::class, 'mdkt_route_id');
    }

    /**
     * @return BelongsTo<ConsolidatedTrip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedTrip::class, 'consolidated_trip_id');
    }
}
