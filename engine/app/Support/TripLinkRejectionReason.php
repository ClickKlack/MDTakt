<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TripLinkRejection;
use App\Http\Requests\TripLinkRequest;

/**
 * Ein abgelehnter Anschluss samt Begründung.
 *
 * Bewusst ein Rückgabewert und keine Exception: Derselbe Grund wird an zwei Stellen gebraucht,
 * die ihn verschieden behandeln. Der Einzelklick macht daraus einen 422
 * ({@see TripLinkRequest}), der Mengen-Lauf überspringt eine Zeile und läuft
 * weiter. Eine Exception zwänge die zweite Stelle, sie sofort wieder zu fangen.
 */
final readonly class TripLinkRejectionReason
{
    public function __construct(
        public TripLinkRejection $code,
        public string $message,
    ) {}
}
