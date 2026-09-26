<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MdktRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MdktRoute>
 */
final class MdktRouteFactory extends Factory
{
    protected $model = MdktRoute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Linie 1, drei Halte, erfasst an einem Sommertag (MESZ: UTC+2) — 04:00Z ist 06:00 lokal.
        return [
            'fingerprint' => hash('sha256', $this->faker->unique()->uuid()),
            'mdkt_trip_id' => $this->faker->numberBetween(1, 99999),
            'line' => '1',
            'direction' => 'Sudenburg',
            'stops' => [
                ['seq' => 1, 'hafas_stop_id' => '900001', 'stop_name' => 'A', 'line' => '1', 'arrival_planned' => null, 'departure_planned' => '2026-09-01T04:00:00Z'],
                ['seq' => 2, 'hafas_stop_id' => '900002', 'stop_name' => 'B', 'line' => '1', 'arrival_planned' => null, 'departure_planned' => '2026-09-01T04:10:00Z'],
                ['seq' => 3, 'hafas_stop_id' => '900003', 'stop_name' => 'C', 'line' => '1', 'arrival_planned' => '2026-09-01T04:20:00Z', 'departure_planned' => null],
            ],
        ];
    }
}
