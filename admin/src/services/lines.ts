import api from './api'

/**
 * Eine MVB-Linie — identifiziert über `route_short_name`. Mehrere GTFS-Routen unter
 * derselben Bezeichnung (z. B. Schienenersatzverkehr als Bus) ergeben einen Eintrag;
 * `route_type`/`mode` beschreiben die prägende Route, `modes` alle beteiligten.
 */
export interface Line {
  route_short_name: string
  route_type: number
  mode: 'tram' | 'bus' | 'other'
  modes: ('tram' | 'bus' | 'other')[]
  route_ids: string[]
  // Im Admin gepflegte Linienfarbe (Hex inkl. '#'); null = nicht gesetzt → Fallback.
  color?: string | null
}

/** Linienfarbe setzen (Admin/Sanctum). */
export async function setLineColor(line: string, color: string): Promise<void> {
  await api.put(`/api/v1/admin/line-colors/${encodeURIComponent(line)}`, { color })
}

/** Linienfarbe zurücksetzen (Admin/Sanctum). */
export async function resetLineColor(line: string): Promise<void> {
  await api.delete(`/api/v1/admin/line-colors/${encodeURIComponent(line)}`)
}

/** Betriebstag-Klasse — Werte identisch zum PHP-Enum FahrplanTyp. */
export type FahrplanTyp = 'mo_fr' | 'mo_fr_ferien' | 'sa' | 'so_feiertag'

export const FAHRPLAN_TYPEN: { value: FahrplanTyp; label: string }[] = [
  { value: 'mo_fr', label: 'Mo-Fr' },
  { value: 'mo_fr_ferien', label: 'Mo-Fr (Ferien)' },
  { value: 'sa', label: 'Samstag' },
  { value: 'so_feiertag', label: 'So + Feiertage' },
]

export interface LineTripItem {
  trip_id: string
  service_id: string
  departure_time: string | null
  arrival_time: string | null
  // Wöchentliches Verkehrsmuster („Mo-Fr", „So", „täglich"); null = kein Wochenmuster.
  day_pattern: string | null
  // Einzeltermine (YYYY-MM-DD) — nur gefüllt, wenn day_pattern null ist.
  service_dates: string[]
  // Verkehrsmittel dieser Fahrt — unterscheidet Routen gleicher Linienbezeichnung.
  mode: 'tram' | 'bus' | 'other'
}

export interface LineTripGroup {
  start_stop: string
  end_stop: string
  trip_count: number
  trips: LineTripItem[]
}

export interface LineTrips {
  line: string
  trip_count: number
  // Gesetzter Fahrplantyp-Filter; null = alle Betriebstage nebeneinander.
  day_type: FahrplanTyp | null
  day_type_label: string | null
  // Stichtag, über den der Typ aufgelöst wurde; null = ungefiltert oder nicht im Feed.
  reference_date: string | null
  // Vorkommende Verkehrsmittel; mehr als eines = Linienbezeichnung auf mehreren Routen.
  modes: ('tram' | 'bus' | 'other')[]
  groups: LineTripGroup[]
}

/** Alle MVB-Linien. */
export async function fetchLines(): Promise<Line[]> {
  const { data } = await api.get('/api/v1/lines')
  return data.data
}

/**
 * Fahrten einer Linie, gruppiert nach Start → Ziel. Ohne `dayType` enthält das
 * Ergebnis alle Betriebstage — dieselbe Abfahrtszeit kann dann mehrfach vorkommen.
 */
export async function fetchLineTrips(line: string, dayType?: FahrplanTyp | null): Promise<LineTrips> {
  const { data } = await api.get(`/api/v1/lines/${encodeURIComponent(line)}/trips`, {
    params: dayType ? { day_type: dayType } : {},
  })
  return data.data
}
