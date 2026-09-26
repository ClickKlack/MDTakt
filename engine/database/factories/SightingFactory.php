<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SightingMatch;
use App\Enums\SightingStatus;
use App\Models\MdktRoute;
use App\Models\Sighting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sighting>
 */
final class SightingFactory extends Factory
{
    protected $model = Sighting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mdkt_recording_id' => $this->faker->unique()->numberBetween(1, 9_999_999),
            'mdkt_route_id' => MdktRoute::factory(),
            'line' => '1',
            'course_number' => '03',
            'hafas_stop_id' => '900002',
            'stop_name' => 'B',
            'service_date' => '2026-09-01',
            'observed_at' => '2026-09-01T04:09:30Z',
            'departure_planned' => '2026-09-01T04:10:00Z',
            'departure_actual' => null,
            'trip_signature' => null,
            'consolidated_trip_id' => null,
            'match' => SightingMatch::Waiting,
            'match_attempts' => 0,
            'status' => SightingStatus::Pending,
        ];
    }
}
