<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Validiert den Sichtungs-Eingang aus MDKursTracker (INTEGRATION_MDKURSTRACKER §5.1).
 *
 * Die Grenzen sind hart gezogen: Der Tracker schickt per Cron nach einer Karenzzeit, im
 * Normalfall einige Sichtungen, beim ersten Lauf den Altbestand in Blöcken. Alles darüber ist kein Betrieb, sondern ein Fehler oder ein Angriff.
 * Zeitstempel ausschließlich als ISO-8601 in UTC mit `Z` — eine lokale oder naive Zeit ließe sich
 * nicht eindeutig auf den Fahrplan legen.
 */
final class SightingIngestRequest extends ApiFormRequest
{
    public const MAX_SIGHTINGS = 500;

    public const MAX_TRIPS = 200;

    public const MAX_STOPS = 150;

    private const UTC = 'date_format:Y-m-d\TH:i:s\Z';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::ruleSet();
    }

    /**
     * Die Regeln, auf Wunsch ohne Mengengrenzen — für `sightings:ingest-file`, das einen ganzen
     * Tracker-Export von Hand einspielt und nicht über das Netz kommt.
     *
     * @return array<string, mixed>
     */
    public static function ruleSet(bool $limited = true): array
    {
        $max = static fn (int $n): array => $limited ? ['max:'.$n] : [];

        return [
            'sync' => ['sometimes', 'array'],
            'sync.since' => ['nullable', self::UTC],
            'sync.generated_at' => ['nullable', self::UTC],

            'trips' => ['present', 'array', ...$max(self::MAX_TRIPS)],
            'trips.*.mdkt_trip_id' => ['nullable', 'integer', 'min:1'],
            'trips.*.schedule_fingerprint' => ['required', 'string', 'max:128', 'distinct'],
            'trips.*.line' => ['required', 'string', 'max:8'],
            'trips.*.direction' => ['nullable', 'string', 'max:255'],
            'trips.*.day_type' => ['nullable', 'string', 'max:16'],
            'trips.*.service_nr' => ['nullable', 'string', 'max:64'],
            'trips.*.stops' => ['required', 'array', 'min:2', 'max:'.self::MAX_STOPS],
            'trips.*.stops.*.seq' => ['required', 'integer', 'min:0'],
            'trips.*.stops.*.hafas_stop_id' => ['required', 'string', 'max:32'],
            'trips.*.stops.*.stop_name' => ['nullable', 'string', 'max:255'],
            'trips.*.stops.*.line' => ['required', 'string', 'max:8'],
            'trips.*.stops.*.arrival_planned' => ['nullable', self::UTC],
            'trips.*.stops.*.departure_planned' => ['nullable', 'required_without:trips.*.stops.*.arrival_planned', self::UTC],

            'sightings' => ['present', 'array', ...$max(self::MAX_SIGHTINGS)],
            'sightings.*.mdkt_recording_id' => ['required', 'integer', 'min:1', 'distinct'],
            'sightings.*.schedule_fingerprint' => ['required', 'string', 'max:128'],
            'sightings.*.hafas_stop_id' => ['required', 'string', 'max:32'],
            'sightings.*.stop_name' => ['nullable', 'string', 'max:255'],
            'sightings.*.line' => ['required', 'string', 'max:8'],
            'sightings.*.course_number' => ['required', 'string', 'max:8'],
            'sightings.*.service_date' => ['required', 'date_format:Y-m-d'],
            'sightings.*.observed_at' => ['required', self::UTC],
            'sightings.*.departure_planned' => ['required', self::UTC],
            'sightings.*.departure_actual' => ['nullable', self::UTC],
        ];
    }
}
