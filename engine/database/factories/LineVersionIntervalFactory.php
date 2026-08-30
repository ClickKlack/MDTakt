<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LineVersion;
use App\Models\LineVersionInterval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LineVersionInterval>
 */
final class LineVersionIntervalFactory extends Factory
{
    protected $model = LineVersionInterval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'line_version_id' => LineVersion::factory(),
            'valid_from' => '2026-08-17',
            'valid_to' => '2026-09-18',
            // Vorgabe: beide Grenzen offen — so sieht ein Intervall aus, das an den
            // Feed-Fensterkanten anliegt (FAHRPLANPERIODEN §5.4 b).
            'from_confirmed' => false,
            'to_confirmed' => false,
        ];
    }

    public function confirmed(): self
    {
        return $this->state(fn (): array => ['from_confirmed' => true, 'to_confirmed' => true]);
    }
}
