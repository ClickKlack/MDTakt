<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsolidatedTrip;
use App\Models\Course;
use App\Models\CourseTrip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseTrip>
 */
final class CourseTripFactory extends Factory
{
    protected $model = CourseTrip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'consolidated_trip_id' => ConsolidatedTrip::factory(),
        ];
    }
}
