<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FahrplanTyp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ein Fahrplanstand einer Linie für einen Betriebstag-Typ (FAHRPLANPERIODEN §4.2).
 * Identifiziert über den Fingerprint — die Gültigkeit liegt in den Intervallen.
 *
 * @property int $id
 * @property int $period_id
 * @property string $line
 * @property FahrplanTyp $day_type
 * @property int $version_no
 * @property string $fingerprint
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
final class LineVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['period_id', 'line', 'day_type', 'version_no', 'fingerprint', 'first_seen_at', 'last_seen_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_type' => FahrplanTyp::class,
            'version_no' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SchedulePeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(SchedulePeriod::class, 'period_id');
    }

    /**
     * @return HasMany<LineVersionInterval, $this>
     */
    public function intervals(): HasMany
    {
        return $this->hasMany(LineVersionInterval::class, 'line_version_id');
    }
}
