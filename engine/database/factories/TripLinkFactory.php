<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TripLinkKind;
use App\Models\ConsolidatedStop;
use App\Models\TripLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripLink>
 */
final class TripLinkFactory extends Factory
{
    protected $model = TripLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_trip_id' => null,
            'to_trip_id' => null,
            'stop_id' => ConsolidatedStop::factory(),
            'kind' => TripLinkKind::Link,
            'note' => null,
        ];
    }

    /** Ausrücken: Die Kette beginnt hier, bewusst ohne Vorgänger. */
    public function start(): self
    {
        return $this->state(fn (): array => ['kind' => TripLinkKind::Start, 'from_trip_id' => null]);
    }

    /** Einrücken: Die Kette endet hier, bewusst ohne Nachfolger. */
    public function end(): self
    {
        return $this->state(fn (): array => ['kind' => TripLinkKind::End, 'to_trip_id' => null]);
    }
}
