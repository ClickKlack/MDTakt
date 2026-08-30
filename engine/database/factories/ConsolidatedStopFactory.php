<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsolidatedStop;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsolidatedStop>
 */
final class ConsolidatedStopFactory extends Factory
{
    protected $model = ConsolidatedStop::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $jetzt = CarbonImmutable::now();

        // Magdeburger Stadtgebiet, damit Testdaten plausibel liegen.
        return [
            'anchor_lat' => sprintf('%.7F', $this->faker->randomFloat(7, 52.05, 52.19)),
            'anchor_lon' => sprintf('%.7F', $this->faker->randomFloat(7, 11.57, 11.68)),
            'name_key' => $this->faker->unique()->lexify('halt????'),
            'first_seen_at' => $jetzt,
            'last_seen_at' => $jetzt,
        ];
    }
}
