<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FahrplanTyp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stabile Fahrt-Identität je (Trip, Fahrplantyp) — FAHRPLANPERIODEN §5.1/§6.1.
 * Wird bei jedem Import neu aufgebaut; die trip_id ist nur ein Zeiger.
 *
 * @property int $id
 * @property string $trip_id
 * @property FahrplanTyp $day_type
 * @property string $signature
 */
final class TripSignature extends Model
{
    public $timestamps = false;

    protected $fillable = ['trip_id', 'day_type', 'signature'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day_type' => FahrplanTyp::class];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }
}
