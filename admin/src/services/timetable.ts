import api from './api'
import type { VersionInterval } from './scheduleVersions'

/**
 * Eine Zeile der Fahrplan-Matrix. Die Zeile ist eine **Position in der Haltefolge**, nicht eine
 * Halt-Identität: Wendeschleifen und Stichabstecher berühren denselben Halt zweimal, und beide
 * Berührungen brauchen eine eigene Zeile. `repeat_index` unterscheidet sie.
 */
export interface TimetableRow {
  position: number
  stop_id: number
  stop_name: string
  repeat_index: number
}

export interface TimetableTrip {
  id: number
  signature: string
  mode: 'tram' | 'bus' | 'other'
  departure_time: string | null
  arrival_time: string | null
  /** Zeiten als „HH:MM", positionsgleich zu `rows`; null = Zeile wird nicht bedient. */
  cells: (string | null)[]
}

export interface TimetableDirection {
  key: string
  start_stop: string
  end_stop: string
  trip_count: number
  /** Über 1: mehrere Laufwege wurden auf eine gemeinsame Zeilenachse ausgerichtet. */
  variant_count: number
  /** Die Ausrichtung ist so weit auseinandergelaufen, dass die Tabelle in die Irre führen kann. */
  alignment_warning: boolean
  rows: TimetableRow[]
  trips: TimetableTrip[]
}

export interface TimetableVersion {
  id: number
  line: string
  day_type: string
  day_type_label: string
  version_no: number
  fingerprint: string
  trip_count: number
  intervals: VersionInterval[]
}

export interface Timetable {
  line_version: TimetableVersion
  period: { id: number; label: string; status: 'current' | 'frozen' } | null
  directions: TimetableDirection[]
}

export async function fetchTimetable(lineVersionId: number): Promise<Timetable> {
  const { data } = await api.get(`/api/v1/admin/line-versions/${lineVersionId}/timetable`)
  return data.data
}
