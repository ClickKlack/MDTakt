<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PeriodOrigin;
use App\Enums\PeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Netzweite Fahrplanperiode (FAHRPLANPERIODEN §4.1).
 *
 * @property int $id
 * @property string $label
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property PeriodStatus $status
 * @property PeriodOrigin $created_via
 */
final class SchedulePeriod extends Model
{
    protected $fillable = ['label', 'valid_from', 'valid_to', 'status', 'created_via'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'status' => PeriodStatus::class,
            'created_via' => PeriodOrigin::class,
        ];
    }

    /**
     * @return HasMany<LineVersion, $this>
     */
    public function lineVersions(): HasMany
    {
        return $this->hasMany(LineVersion::class, 'period_id');
    }
}
