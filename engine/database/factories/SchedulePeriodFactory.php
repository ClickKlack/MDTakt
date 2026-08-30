<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PeriodOrigin;
use App\Enums\PeriodStatus;
use App\Models\SchedulePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchedulePeriod>
 */
final class SchedulePeriodFactory extends Factory
{
    protected $model = SchedulePeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => 'Testperiode',
            'valid_from' => '2026-08-15',
            'valid_to' => null,
            'status' => PeriodStatus::Current,
            'created_via' => PeriodOrigin::Bootstrap,
        ];
    }

    public function frozen(string $validTo): self
    {
        return $this->state(fn (): array => [
            'status' => PeriodStatus::Frozen,
            'valid_to' => $validTo,
        ]);
    }
}
