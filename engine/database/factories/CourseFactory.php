<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FahrplanTyp;
use App\Models\Course;
use App\Models\SchedulePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
final class CourseFactory extends Factory
{
    protected $model = Course::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period_id' => SchedulePeriod::factory(),
            'day_type' => FahrplanTyp::MoFrNormal,
            // Zweistellig wie am Fahrzeug angeschlagen (INTEGRATION §2: CHAR(2)).
            'number' => $this->faker->unique()->numerify('##'),
            'note' => null,
        ];
    }
}
