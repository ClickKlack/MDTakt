<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StopTime>
 */
final class StopTimeFactory extends Factory
{
    protected $model = StopTime::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $time = sprintf('%02d:%02d:00', $this->faker->numberBetween(4, 23), $this->faker->numberBetween(0, 59));

        return [
            'trip_id' => Trip::factory(),
            'stop_id' => Stop::factory(),
            'arrival_time' => $time,
            'departure_time' => $time,
            'stop_sequence' => $this->faker->unique()->numberBetween(1, 60),
        ];
    }
}
