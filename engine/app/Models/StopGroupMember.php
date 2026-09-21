<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StopGroupOrigin;
use Database\Factories\StopGroupMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zugehörigkeit eines Halts zu einer Haltestelle.
 *
 * `assigned_via = manual` schützt die Zeile vor dem Namensabgleich — siehe
 * {@see StopGroupOrigin}.
 *
 * @property int $id
 * @property int $stop_group_id
 * @property int $consolidated_stop_id
 * @property StopGroupOrigin $assigned_via
 */
final class StopGroupMember extends Model
{
    /** @use HasFactory<StopGroupMemberFactory> */
    use HasFactory;

    protected $fillable = ['stop_group_id', 'consolidated_stop_id', 'assigned_via'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['assigned_via' => StopGroupOrigin::class];
    }

    /**
     * @return BelongsTo<StopGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(StopGroup::class, 'stop_group_id');
    }

    /**
     * @return BelongsTo<ConsolidatedStop, $this>
     */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedStop::class, 'consolidated_stop_id');
    }
}
