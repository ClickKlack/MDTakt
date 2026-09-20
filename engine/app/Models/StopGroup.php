<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StopGroupOrigin;
use Database\Factories\StopGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine **Haltestelle** als Betriebspunkt: die Klammer um die Halte, die im Betrieb derselbe Ort
 * sind — üblicherweise die Richtungs-Bahnsteige (KURSE §3.1).
 *
 * Nicht zu verwechseln mit {@see ConsolidatedStop}: Das ist ein einzelner physischer Punkt.
 * „Rothensee" und „Rothensee (Schleife)" sind zwei solcher Punkte und **eine** Haltestelle.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_key
 * @property StopGroupOrigin $created_via
 * @property string|null $note
 */
final class StopGroup extends Model
{
    /** @use HasFactory<StopGroupFactory> */
    use HasFactory;

    protected $fillable = ['name', 'name_key', 'created_via', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_via' => StopGroupOrigin::class];
    }

    /**
     * @return HasMany<StopGroupMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(StopGroupMember::class, 'stop_group_id');
    }
}
