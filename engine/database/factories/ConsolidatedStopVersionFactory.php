<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsolidatedStop;
use App\Models\ConsolidatedStopVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsolidatedStopVersion>
 */
final class ConsolidatedStopVersionFactory extends Factory
{
    protected $model = ConsolidatedStopVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consolidated_stop_id' => ConsolidatedStop::factory(),
            'name' => $this->faker->unique()->streetName(),
            'lat' => sprintf('%.7F', $this->faker->randomFloat(7, 52.05, 52.19)),
            'lon' => sprintf('%.7F', $this->faker->randomFloat(7, 11.57, 11.68)),
            'valid_from' => '2026-08-15',
            'valid_to' => '2026-09-28',
            'from_confirmed' => false,
            'to_confirmed' => false,
        ];
    }
}
