<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FahrplanTyp;
use App\Models\LineVersion;
use App\Models\SchedulePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LineVersion>
 */
final class LineVersionFactory extends Factory
{
    protected $model = LineVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $jetzt = CarbonImmutable::now();

        return [
            'period_id' => SchedulePeriod::factory(),
            'line' => '1',
            'day_type' => FahrplanTyp::MoFrNormal,
            'version_no' => 1,
            // Der echte Fingerprint ist der SHA ueber die sortierten Fahrt-Signaturen; fuer
            // Testdaten genuegt ein eindeutiger Wert, solange er je Version verschieden ist.
            'fingerprint' => hash('sha256', $this->faker->unique()->uuid()),
            'first_seen_at' => $jetzt,
            'last_seen_at' => $jetzt,
        ];
    }
}
