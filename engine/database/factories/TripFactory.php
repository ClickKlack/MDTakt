<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Route;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trip>
 */
final class TripFactory extends Factory
{
    protected $model = Trip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_id' => 'T'.$this->faker->unique()->numberBetween(1, 100000),
            'route_id' => Route::factory(),
            'service_id' => 'W'.$this->faker->numberBetween(1, 9),
            'block_id' => null,
            'direction_id' => $this->faker->randomElement([0, 1]),
        ];
    }
}
