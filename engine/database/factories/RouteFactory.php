<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Route;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Route>
 */
final class RouteFactory extends Factory
{
    protected $model = Route::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shortName = (string) $this->faker->numberBetween(1, 99);

        return [
            'route_id' => 'R'.$this->faker->unique()->numberBetween(1, 100000),
            'route_short_name' => $shortName,
            // 0 = Tram, 3 = Bus (GTFS route_type)
            'route_type' => $this->faker->randomElement([0, 3]),
        ];
    }

    public function tram(): self
    {
        return $this->state(['route_type' => 0]);
    }

    public function bus(): self
    {
        return $this->state(['route_type' => 3]);
    }
}
