<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CalendarDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarDate>
 */
final class CalendarDateFactory extends Factory
{
    protected $model = CalendarDate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => 'W'.$this->faker->numberBetween(1, 9),
            'date' => '2026-06-22',
            // 1 = Betrieb zusätzlich, 2 = Betrieb entfällt
            'exception_type' => 1,
        ];
    }

    public function added(): self
    {
        return $this->state(['exception_type' => 1]);
    }

    public function removed(): self
    {
        return $this->state(['exception_type' => 2]);
    }
}
