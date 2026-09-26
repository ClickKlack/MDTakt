import { ref } from 'vue'
import api from './api'
import type { FahrplanTyp } from './lines'

/**
 * Sichtungen aus MDKursTracker. Der Vergleich mit dem lokal gepflegten Kurs rechnet die Engine
 * bei jeder Abfrage neu — er bleibt richtig, auch wenn im Fahrplan inzwischen gepflegt wurde.
 */
export type SightingMatch = 'matched' | 'matched_next_version' | 'waiting' | 'no_trip' | 'ambiguous'
export type SightingStatus = 'pending' | 'confirmed' | 'accepted' | 'rejected'
/** same = Kurs hängt schon an der Fahrt · differs = anderer Kurs · none = Fahrt ohne Kurs */
export type SightingComparison = 'same' | 'differs' | 'none' | 'no_trip'
export type SightingState = 'open' | 'waiting' | 'confirmed' | 'accepted' | 'rejected' | 'all'

export const SIGHTING_STATES: { value: SightingState; label: string }[] = [
  { value: 'open', label: 'Offen' },
  { value: 'waiting', label: 'Wartet auf Fahrplan' },
  { value: 'confirmed', label: 'Bestätigt (automatisch)' },
  { value: 'accepted', label: 'Angenommen' },
  { value: 'rejected', label: 'Abgelehnt' },
  { value: 'all', label: 'Alle' },
]

export interface SightingTrip {
  id: number
  line: string
  line_version_id: number
  version_no: number
  day_type: FahrplanTyp | null
  period_id: number | null
  start_stop: string | null
  end_stop: string | null
  /** GTFS-Lokalzeit „HH:MM:SS" — über formatClock anzeigen */
  departure_time: string | null
  arrival_time: string | null
}

export interface Sighting {
  id: number
  mdkt_recording_id: number
  line: string
  course_number: string
  /** Linie/Kurs, z. B. „9/13" */
  display: string
  hafas_stop_id: string
  stop_name: string | null
  /** Betriebstag, reines Kalenderdatum */
  service_date: string
  observed_at: string
  departure_planned: string
  departure_actual: string | null
  route_fingerprint: string
  match: SightingMatch
  match_attempts: number
  status: SightingStatus
  status_label: string
  decided_at: string | null
  decision_note: string | null
  trip: SightingTrip | null
  local_course: { id: number; number: string; display: string } | null
  comparison: SightingComparison
  /** Länge der Kette, auf die ein Annehmen den Kurs setzt (nur bei offenen Sichtungen mit Fahrt) */
  chain_trip_count: number | null
}

export interface SightingFilter {
  state: SightingState
  line?: string | null
  date_from?: string | null
  date_to?: string | null
  differs_only?: boolean
}

export interface SightingPage {
  items: Sighting[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export async function fetchSightings(filter: SightingFilter, page = 1, perPage = 50): Promise<SightingPage> {
  const { data } = await api.get('/api/v1/admin/sightings', {
    params: {
      state: filter.state,
      line: filter.line || undefined,
      date_from: filter.date_from || undefined,
      date_to: filter.date_to || undefined,
      differs_only: filter.differs_only ? 'true' : undefined,
      page,
      per_page: perPage,
    },
  })
  return { items: data.data, meta: data.meta }
}

export interface AcceptResult {
  accepted: number
  /** Fahrten, die den Kurs bekamen — die ganze Kette */
  trips_assigned: number
  /** weitere offene Sichtungen derselben Kette, die dadurch von selbst stimmen */
  confirmed_others: number
}

export async function acceptSightings(ids: number[]): Promise<AcceptResult> {
  const { data } = await api.post('/api/v1/admin/sightings/accept', { ids })
  void refreshSightingCounts()
  return data.data
}

export async function rejectSightings(ids: number[], note?: string | null): Promise<{ rejected: number }> {
  const { data } = await api.post('/api/v1/admin/sightings/reject', { ids, note: note || null })
  void refreshSightingCounts()
  return data.data
}

/** Zahl offener Sichtungen für die Navigation — geteilt, damit jede Entscheidung sie nachzieht. */
export const sightingCounts = ref<{ open: number; waiting: number } | null>(null)

export async function refreshSightingCounts(): Promise<void> {
  try {
    const { data } = await api.get('/api/v1/admin/sightings/counts')
    sightingCounts.value = data.data
  } catch {
    // Die Zahl ist ein Hinweis, kein Inhalt — ohne sie bleibt die Navigation benutzbar.
  }
}

/**
 * Die Rückfrage vor dem Annehmen. Weicht der lokale Kurs ab, wird die **ganze Kette**
 * umnummeriert — das soll niemand aus Versehen auslösen. `null` heißt: ohne Rückfrage annehmen.
 */
export function acceptQuestion(
  s: Pick<Sighting, 'comparison' | 'local_course' | 'course_number' | 'chain_trip_count' | 'match'>,
  linie: string,
): string | null {
  const zeilen: string[] = []

  if (s.comparison === 'differs' && s.local_course) {
    zeilen.push(
      `Kette mit ${s.chain_trip_count ?? '?'} Fahrt(en) umnummerieren: ${s.local_course.display} → ${linie}/${s.course_number}?`,
    )
  }

  if (s.match === 'matched_next_version') {
    zeilen.push(
      'Die Fahrt wurde erst in der Folgeversion gefunden (der Fahrplan kannte den Laufweg am Tag der Sichtung noch nicht). Trotzdem annehmen?',
    )
  }

  return zeilen.length > 0 ? zeilen.join('\n\n') : null
}
