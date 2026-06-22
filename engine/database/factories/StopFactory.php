<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Stop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stop>
 */
final class StopFactory extends Factory
{
    protected $model = Stop::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stop_id' => 'S'.$this->faker->unique()->numberBetween(1, 100000),
            'stop_name' => $this->faker->streetName(),
            'lat' => $this->faker->latitude(52.0, 52.2),
            'lon' => $this->faker->longitude(11.5, 11.7),
        ];
    }
}
