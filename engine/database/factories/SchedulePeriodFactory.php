<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PeriodOrigin;
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
            'created_via' => PeriodOrigin::Bootstrap,
        ];
    }

    /**
     * Eine abgelaufene Periode. Es gibt keinen Status zu setzen — er ergibt sich aus
     * `valid_to`, und ein `valid_to` in der Vergangenheit *ist* eingefroren.
     */
    public function frozen(string $validTo): self
    {
        return $this->state(fn (): array => ['valid_to' => $validTo]);
    }
}
