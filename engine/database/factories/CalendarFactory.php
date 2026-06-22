<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Calendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Calendar>
 */
final class CalendarFactory extends Factory
{
    protected $model = Calendar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => 'W'.$this->faker->unique()->numberBetween(1, 100000),
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => true,
            'friday' => true,
            'saturday' => false,
            'sunday' => false,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ];
    }

    /**
     * Nur an Wochenenden gültiges Wochenmuster.
     */
    public function weekend(): self
    {
        return $this->state([
            'monday' => false,
            'tuesday' => false,
            'wednesday' => false,
            'thursday' => false,
            'friday' => false,
            'saturday' => true,
            'sunday' => true,
        ]);
    }
}
