<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StopGroupOrigin;
use App\Models\ConsolidatedStop;
use App\Models\StopGroup;
use App\Models\StopGroupMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StopGroupMember>
 */
final class StopGroupMemberFactory extends Factory
{
    protected $model = StopGroupMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stop_group_id' => StopGroup::factory(),
            'consolidated_stop_id' => ConsolidatedStop::factory(),
            'assigned_via' => StopGroupOrigin::Auto,
        ];
    }

    /** Von Hand zugeordnet — die Automatik fasst diese Zeile nicht mehr an. */
    public function manual(): self
    {
        return $this->state(fn (): array => ['assigned_via' => StopGroupOrigin::Manual]);
    }
}
