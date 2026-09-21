<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Warum ein Anschluss unzulässig ist (KURSE §4).
 *
 * Dieselben Gründe, die der Einzelklick mit 422 abweist — nur trägt ein Mengen-Lauf sie nicht als
 * Abbruch, sondern als Begründung an einer übersprungenen Zeile. Deshalb braucht der Grund einen
 * maschinenlesbaren Code: Die Oberfläche soll „vier Fahrten wegen Gattungswechsel" zusammenfassen
 * können, statt vier gleichlautende Sätze untereinander zu stellen.
 *
 * Nicht enthalten ist der **Linienwechsel**: Er ist erlaubt und der Normalfall einer
 * Fahrzeugkette (K1), und erscheint als Hinweis, nicht als Ablehnung.
 */
enum TripLinkRejection: string
{
    case SameTrip = 'same_trip';
    case DifferentPeriod = 'different_period';
    case DifferentDayType = 'different_day_type';
    case ModeChange = 'mode_change';
    case StopGroupMismatch = 'stop_group_mismatch';
    case NoCommonValidity = 'no_common_validity';
    case Cycle = 'cycle';
    case NegativeTurnaround = 'negative_turnaround';
}
