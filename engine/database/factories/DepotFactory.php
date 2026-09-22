<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Depot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Depot>
 */
final class DepotFactory extends Factory
{
    protected $model = Depot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Betriebshof '.$this->faker->unique()->word(),
            'short_name' => null,
            // Leer heisst „alle Verkehrsmittel" — der Vorgabefall.
            'modes' => null,
            'active' => true,
            'note' => null,
        ];
    }

    /**
     * Nur fuer ein Verkehrsmittel — die Auswahl an der Fahrt bietet ihn dann nur dort an.
     *
     * @param  array<int, string>  $modes
     */
    public function forModes(array $modes): self
    {
        return $this->state(fn (): array => ['modes' => $modes]);
    }

    /** Stillgelegt: bleibt an alten Entscheidungen stehen, wird aber nicht mehr angeboten. */
    public function inactive(): self
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
