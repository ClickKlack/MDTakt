<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsolidatedStop;
use App\Models\ConsolidatedStopTime;
use App\Models\ConsolidatedTrip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsolidatedStopTime>
 */
final class ConsolidatedStopTimeFactory extends Factory
{
    protected $model = ConsolidatedStopTime::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $zeit = sprintf('%02d:%02d:00', $this->faker->numberBetween(4, 23), $this->faker->numberBetween(0, 59));

        return [
            'consolidated_trip_id' => ConsolidatedTrip::factory(),
            'stop_id' => ConsolidatedStop::factory(),
            'stop_sequence' => 1,
            'arrival_time' => $zeit,
            'departure_time' => $zeit,
        ];
    }
}
