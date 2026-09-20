<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StopGroupOrigin;
use App\Models\StopGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StopGroup>
 */
final class StopGroupFactory extends Factory
{
    protected $model = StopGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->streetName();

        return [
            'name' => $name,
            'name_key' => mb_strtolower(preg_replace('/[^a-z]+/i', '', $name) ?? $name),
            'created_via' => StopGroupOrigin::Auto,
            'note' => null,
        ];
    }

    /** Von Hand angelegt — traegt keinen Namensschluessel und sammelt nichts automatisch ein. */
    public function manual(): self
    {
        return $this->state(fn (): array => [
            'name_key' => null,
            'created_via' => StopGroupOrigin::Manual,
        ]);
    }
}
