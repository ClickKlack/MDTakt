<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SchoolHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolHoliday>
 */
final class SchoolHolidayFactory extends Factory
{
    protected $model = SchoolHoliday::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Ferien '.$this->faker->unique()->numberBetween(1, 100000),
            'start_date' => '2026-07-06',
            'end_date' => '2026-08-16',
        ];
    }
}
