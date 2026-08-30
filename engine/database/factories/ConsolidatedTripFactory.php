<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsolidatedTrip;
use App\Models\LineVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsolidatedTrip>
 */
final class ConsolidatedTripFactory extends Factory
{
    protected $model = ConsolidatedTrip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'line_version_id' => LineVersion::factory(),
            'signature' => hash('sha256', $this->faker->unique()->uuid()),
            'route_type' => 0,
            'first_stop_id' => null,
            'last_stop_id' => null,
        ];
    }
}
