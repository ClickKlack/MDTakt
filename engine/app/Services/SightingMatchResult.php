<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SightingMatch;

/**
 * Ergebnis von {@see SightingMatcher::match()}.
 *
 * `match === null` heißt „nichts gefunden" — ob daraus `waiting` oder `no_trip` wird, entscheidet
 * der Aufrufer anhand der Zahl der Importe, die schon erfolglos waren.
 */
final readonly class SightingMatchResult
{
    public function __construct(
        public ?SightingMatch $match,
        public ?int $tripId = null,
        public ?string $signature = null,
        public ?string $operatingDate = null,
        public ?string $reason = null,
    ) {}

    public static function none(string $reason, ?string $operatingDate = null): self
    {
        return new self(null, reason: $reason, operatingDate: $operatingDate);
    }
}
