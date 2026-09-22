<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TripLinkKind;
use Database\Factories\TripLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Umlauf-Entscheidung: der Anschluss zweier Fahrten an einem Halt, oder die bewusste
 * Aussage, dass eine Kette hier beginnt bzw. endet (KURSE §3).
 *
 * Die Unique-Constraints auf `from_trip_id` und `to_trip_id` tragen die Fachregel: Ein Fahrzeug
 * hat höchstens einen Nachfolger und höchstens einen Vorgänger.
 *
 * @property int $id
 * @property int|null $from_trip_id
 * @property int|null $to_trip_id
 * @property int $stop_id
 * @property TripLinkKind $kind
 * @property int|null $depot_id
 * @property string|null $note
 */
final class TripLink extends Model
{
    /** @use HasFactory<TripLinkFactory> */
    use HasFactory;

    protected $fillable = ['from_trip_id', 'to_trip_id', 'stop_id', 'kind', 'depot_id', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => TripLinkKind::class];
    }

    /**
     * @return BelongsTo<ConsolidatedTrip, $this>
     */
    public function fromTrip(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedTrip::class, 'from_trip_id');
    }

    /**
     * @return BelongsTo<ConsolidatedTrip, $this>
     */
    public function toTrip(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedTrip::class, 'to_trip_id');
    }

    /**
     * @return BelongsTo<ConsolidatedStop, $this>
     */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedStop::class, 'stop_id');
    }

    /**
     * Der Betriebshof, aus dem ausgerückt oder in den eingerückt wird.
     *
     * Nur bei `kind=start`/`end` belegt, und auch dort freiwillig: Beginnt eine Kette an einer
     * Endstelle, steht der Hof nicht fest.
     *
     * @return BelongsTo<Depot, $this>
     */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }
}
